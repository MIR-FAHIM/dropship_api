<?php

namespace App\Service;

use App\Models\CarryBeeOrderCreateForm;
use App\Models\CarrybeeWebhookEvent;
use App\Models\DeliveryAssignedInfo;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrderStatusHistory;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies CarryBee webhook events (https://hackmd.io/_wLl0AtKRHGKBIIsF_xssg?view)
 * to orders: courier tracking, issue flags, status journey and settlement.
 *
 * Entry points:
 *  - process()        real webhook (signature checked)
 *  - processTrusted() catch-up job and admin reprocess (already trusted payloads)
 */
class CarrybeeWebhookService
{
    /** Events that always get a status-history row, even without a status change. */
    private const NOTEWORTHY_EVENTS = [
        'order.create-failed',
        'order.pickup-failed',
        'order.delivery-on-hold',
        'order.delivery-failed',
        'order.exchange',
    ];

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly OrderAccountingService $orderAccountingService,
        private readonly OrderStatusTransitionService $transitions,
    ) {
    }

    public function process(array $payload, string $rawBody, string $signature, array $headers = [], bool $isTest = false): array
    {
        if ((string) ($payload['event'] ?? '') === '') {
            return ['ok' => false, 'http_code' => 422, 'message' => 'Invalid payload: event is required'];
        }

        if (!$isTest && !$this->isValidSignature($rawBody, $signature)) {
            // Keep a trace for the webhook health board (wrong secret on a CarryBee account).
            // A later valid delivery of the same event still processes normally.
            CarrybeeWebhookEvent::firstOrCreate(
                ['fingerprint' => $this->buildFingerprint($payload)],
                [
                    'event' => (string) $payload['event'],
                    'store_id' => Arr::get($payload, 'store_id'),
                    'consignment_id' => Arr::get($payload, 'consignment_id'),
                    'merchant_order_id' => Arr::get($payload, 'merchant_order_id'),
                    'event_time' => Arr::get($payload, 'timestamptz'),
                    'payload' => $payload,
                    'signature_valid' => false,
                    'processing_status' => 'rejected',
                    'message' => 'Invalid webhook signature',
                ]
            );

            return ['ok' => false, 'http_code' => 401, 'message' => 'Invalid webhook signature'];
        }

        return $this->handleVerified($payload, $headers, OrderStatusHistory::SOURCE_WEBHOOK);
    }

    /**
     * For payloads we built or already verified: the catch-up job (from CarryBee's
     * order details API) and the admin "reprocess" action.
     */
    public function processTrusted(array $payload, string $source = OrderStatusHistory::SOURCE_CATCH_UP): array
    {
        if ((string) ($payload['event'] ?? '') === '') {
            return ['ok' => false, 'http_code' => 422, 'message' => 'Invalid payload: event is required'];
        }

        return $this->handleVerified($payload, ['x-resellerbrain-source' => [$source]], $source);
    }

    private function handleVerified(array $payload, array $headers, string $source): array
    {
        $event = (string) $payload['event'];
        $fingerprint = $this->buildFingerprint($payload);
        $webhookEvent = CarrybeeWebhookEvent::where('fingerprint', $fingerprint)->first();

        if ($webhookEvent) {
            $webhookEvent->attempts = (int) $webhookEvent->attempts + 1;
            // Older invalid requests may have left a record for the same event.
            if ($webhookEvent->processed_at !== null && $webhookEvent->signature_valid) {
                $webhookEvent->processing_status = 'duplicate';
                $webhookEvent->message = 'Duplicate webhook ignored';
                $webhookEvent->save();

                return ['ok' => true, 'http_code' => 200, 'message' => 'Duplicate webhook ignored', 'data' => ['event_id' => $webhookEvent->id]];
            }
            $webhookEvent->save(); // keep the retry count
        } else {
            $webhookEvent = CarrybeeWebhookEvent::create([
                'fingerprint' => $fingerprint,
                'event' => $event,
                'store_id' => Arr::get($payload, 'store_id'),
                'consignment_id' => Arr::get($payload, 'consignment_id'),
                'merchant_order_id' => Arr::get($payload, 'merchant_order_id'),
                'invoice_id' => Arr::get($payload, 'invoice_id'),
                'event_time' => Arr::get($payload, 'timestamptz'),
                'request_headers' => $headers,
                'payload' => $payload,
                'processing_status' => 'received',
            ]);
        }

        $webhookEvent->signature_valid = true;

        $eventStatusMap = (array) config('carrybee.event_status_map', []);
        if (!array_key_exists($event, $eventStatusMap)) {
            $webhookEvent->processing_status = 'ignored';
            $webhookEvent->message = 'Unsupported event: ' . $event;
            $webhookEvent->processed_at = now();
            $webhookEvent->save();

            return ['ok' => true, 'http_code' => 200, 'message' => 'Event ignored (unsupported)', 'data' => ['event_id' => $webhookEvent->id]];
        }
        $mappedStatusId = $eventStatusMap[$event];

        $order = $this->resolveOrder($payload);
        if (!$order) {
            $webhookEvent->processing_status = 'ignored';
            $webhookEvent->message = 'Order not found for webhook identifiers';
            $webhookEvent->processed_at = now();
            $webhookEvent->save();

            return ['ok' => true, 'http_code' => 202, 'message' => 'Order not found, event stored', 'data' => ['event_id' => $webhookEvent->id]];
        }

        try {
            return DB::transaction(function () use ($order, $webhookEvent, $event, $mappedStatusId, $payload, $source) {
                $order = Order::lockForUpdate()->findOrFail($order->id);
                $eventRecord = CarrybeeWebhookEvent::lockForUpdate()->findOrFail($webhookEvent->id);
                if ($eventRecord->processed_at !== null && $eventRecord->signature_valid) {
                    return ['ok' => true, 'http_code' => 200, 'message' => 'Duplicate webhook ignored', 'data' => ['event_id' => $eventRecord->id]];
                }

                return $this->processMatchedEvent($order, $eventRecord, $event, $mappedStatusId, $payload, $source);
            });
        } catch (Throwable $e) {
            report($e);
            // Keep a diagnostic record without acknowledging a payout that rolled back.
            CarrybeeWebhookEvent::whereKey($webhookEvent->id)->whereNull('processed_at')->update([
                'order_id' => $order->id,
                'mapped_status_id' => $mappedStatusId,
                'signature_valid' => true,
                'processing_status' => 'failed',
                'message' => 'Processing failed; the webhook may be retried.',
            ]);

            return ['ok' => false, 'http_code' => 500, 'message' => 'Webhook processing failed', 'data' => ['event_id' => $webhookEvent->id]];
        }
    }

    private function processMatchedEvent(Order $order, CarrybeeWebhookEvent $webhookEvent, string $event, ?int $mappedStatusId, array $payload, string $source): array
    {
        $webhookEvent->signature_valid = true;
        $webhookEvent->order_id = $order->id;
        $webhookEvent->mapped_status_id = $mappedStatusId;

        $assigned = DeliveryAssignedInfo::where('order_id', $order->id)->first();
        $isNewest = $this->updateTracking($assigned, $event, $payload);
        $this->applyEventDetails($order, $assigned, $event, $payload, $isNewest);

        $notes = [];

        // order.paid: move to Carrybee Paid first so history reads 10 -> 9.
        if ($event === 'order.paid') {
            $nonPayable = array_merge(Order::CARRYBEE_NON_PAYABLE_STATUSES, [12]);
            if (in_array((int) $order->status, $nonPayable, true)) {
                $webhookEvent->processing_status = 'ignored';
                $webhookEvent->message = 'Payment event received for a non-payable order; manual review required.';
                $webhookEvent->processed_at = now();
                $webhookEvent->save();
                $this->notifyAdmins(
                    'CarryBee paid a cancelled/returned order',
                    "Order {$order->order_number} got a CarryBee payment (invoice " . ($payload['invoice_id'] ?? '—') . ') while cancelled/returned. Please review.'
                );

                return ['ok' => true, 'http_code' => 202, 'message' => $webhookEvent->message, 'data' => ['event_id' => $webhookEvent->id, 'order_id' => $order->id]];
            }

            if ($this->transitions->isFinal((int) $order->status)) {
                // Completed already (e.g. settled by hand): just record CarryBee's invoice.
                $invoiceId = (string) Arr::get($payload, 'invoice_id', '');
                $order->forceFill([
                    'carrybee_invoice_id' => $invoiceId !== '' ? $invoiceId : $order->carrybee_invoice_id,
                    'carrybee_paid_at' => $order->carrybee_paid_at ?? ($this->eventTime($payload) ?? now()),
                    'carrybee_disbursed_to' => $order->carrybee_disbursed_to ?? ($this->hasOwnDeliveryAccount($order->loadMissing(['items.product.vendor', 'vendor'])) ? 'vendor' : 'platform'),
                ])->save();
                if ($assigned) {
                    $assigned->forceFill([
                        'carrybee_invoice_id' => $invoiceId !== '' ? $invoiceId : $assigned->carrybee_invoice_id,
                        'carrybee_paid_at' => $assigned->carrybee_paid_at ?? $order->carrybee_paid_at,
                    ])->save();
                }
                $webhookEvent->invoice_id = $invoiceId ?: $webhookEvent->invoice_id;
                $notes[] = 'CarryBee payment recorded on a completed order; settlement unchanged';
            } else {
                $notes[] = $this->applyStatusTransition($order, 10, $payload, $source);
                $this->handleOrderPaidEvent($order, $webhookEvent, $payload, $assigned);
            }
        } elseif ($event === 'order.pickup-cancelled' && $this->transitions->rank((int) $order->status) > 3) {
            $notes[] = 'Pickup cancelled after pickup; status kept';
        } elseif ($mappedStatusId !== null) {
            $notes[] = $this->applyStatusTransition($order, (int) $mappedStatusId, $payload, $source);
        }

        $statusChanged = in_array('Order status updated', $notes, true);
        if (!$statusChanged && in_array($event, self::NOTEWORTHY_EVENTS, true)) {
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status_id' => (int) $order->status,
                'note' => $this->buildHistoryNote($payload),
                'changed_by' => null,
                'source' => $source,
            ]);
        }

        $statusMessage = $notes ? implode('; ', $notes) : 'Tracking updated';
        $webhookEvent->processing_status = 'processed';
        $webhookEvent->message = $statusMessage;
        $webhookEvent->processed_at = now();
        $webhookEvent->save();

        return [
            'ok' => true,
            'http_code' => 200,
            'message' => 'Webhook processed',
            'data' => [
                'event_id' => $webhookEvent->id,
                'order_id' => $order->id,
                'status' => $statusMessage,
                'disbursed_to' => $webhookEvent->disbursed_to,
                'disbursed_amount' => $webhookEvent->disbursed_amount,
                'invoice_id' => $webhookEvent->invoice_id,
            ],
        ];
    }

    /**
     * Courier stage / attempt / rider, only when this event is not older than the
     * last one stored (CarryBee can deliver events out of order).
     */
    private function updateTracking(?DeliveryAssignedInfo $assigned, string $event, array $payload): bool
    {
        if (!$assigned) {
            return true;
        }

        $eventAt = $this->eventTime($payload);
        $isNewest = $assigned->courier_stage_at === null || $eventAt === null || $eventAt->gte($assigned->courier_stage_at);

        if ($isNewest) {
            $assigned->courier_stage = $event;
            $assigned->courier_stage_at = $eventAt ?? now();
            if (Arr::has($payload, 'attempt') && $payload['attempt'] !== null) {
                $assigned->attempt = (int) $payload['attempt'];
            }
        }

        // transfer_status_id mirrors the mapped status (kept for existing UI).
        $map = (array) config('carrybee.event_status_map', []);
        if (($map[$event] ?? null) !== null) {
            $assigned->transfer_status_id = $map[$event];
        }

        $assigned->save();

        return $isNewest;
    }

    /** Fees, collected amounts, rider, issue flags and review holds. */
    private function applyEventDetails(Order $order, ?DeliveryAssignedInfo $assigned, string $event, array $payload, bool $isNewest): void
    {
        $settled = in_array((int) $order->status, [9, 10], true);

        // Fees: order.created / order.updated carry the authoritative figures.
        if (in_array($event, ['order.created', 'order.updated'], true)) {
            $deliveryFee = Arr::get($payload, 'delivery_fee');
            $codFee = Arr::get($payload, 'cod_fee');
            if ($assigned) {
                if (Arr::has($payload, 'collectable_amount')) {
                    $assigned->collectable_amount = (float) $payload['collectable_amount'];
                }
                if ($deliveryFee !== null) {
                    $assigned->delivery_fee = (float) $deliveryFee;
                }
                if ($codFee !== null) {
                    $assigned->cod_fee = (float) $codFee;
                }
                if ($deliveryFee !== null && $codFee !== null) {
                    $assigned->total_fee = round((float) $deliveryFee + (float) $codFee, 2);
                }
            }
            if (!$settled) {
                if ($deliveryFee !== null) {
                    $order->actual_courier_fee = round((float) $deliveryFee, 2);
                }
                if ($codFee !== null) {
                    $order->actual_cod_fee = round((float) $codFee, 2);
                }
                if ($deliveryFee !== null || $codFee !== null) {
                    $order->actual_courier_cost = round((float) ($order->actual_courier_fee ?? 0) + (float) ($order->actual_cod_fee ?? 0), 2);
                    $order->save();
                }
            }
        }

        if (!$assigned) {
            if ($event === 'order.create-failed') {
                $this->notifyCreateFailed($order, $payload);
            }

            return;
        }

        switch ($event) {
            case 'order.created':
                if ($assigned->issue_flag === 'create_failed') {
                    $this->clearIssue($assigned);
                }
                break;

            case 'order.create-failed':
                $this->raiseIssue($assigned, 'create_failed', $this->describeCauses($payload));
                $this->notifyCreateFailed($order, $payload);
                break;

            case 'order.pickup-failed':
                $this->raiseIssue($assigned, 'pickup', $this->reasonText($payload) ?: 'Pickup failed');
                $this->notifyVendor($order, 'CarryBee pickup failed', "Pickup failed for order {$order->order_number}. Please keep the parcel ready.");
                break;

            case 'order.picked':
                if ($isNewest && $assigned->issue_flag === 'pickup') {
                    $this->clearIssue($assigned);
                }
                break;

            case 'order.assigned-for-delivery':
                $assigned->rider_name = Arr::get($payload, 'agent_name') ?: $assigned->rider_name;
                $assigned->rider_phone = Arr::get($payload, 'agent_phone') ?: $assigned->rider_phone;
                if ($isNewest && $assigned->issue_flag === 'delivery') {
                    $this->clearIssue($assigned);
                }
                break;

            case 'order.delivery-on-hold':
            case 'order.delivery-failed':
                $label = $event === 'order.delivery-failed' ? 'Delivery failed' : 'Delivery on hold';
                $reason = $this->reasonText($payload) ?: $label;
                $attempt = (int) (Arr::get($payload, 'attempt') ?? $assigned->attempt ?? 1);
                $this->raiseIssue($assigned, 'delivery', $reason);
                $this->notifyUser($order->user_id, $label, "{$label} for order {$order->order_number} (attempt {$attempt}): {$reason}. Please contact your customer.");
                if ($event === 'order.delivery-failed' && $attempt >= 2) {
                    $this->notifyAdmins('Repeated delivery failure', "Order {$order->order_number} failed delivery attempt {$attempt}: {$reason}.");
                }
                break;

            case 'order.delivered':
            case 'order.partial-delivery':
            case 'order.paid-return':
                if (Arr::has($payload, 'collected_amount')) {
                    $assigned->collected_amount = (float) $payload['collected_amount'];
                }
                if ($event !== 'order.paid-return' && $assigned->issue_flag === 'delivery') {
                    $this->clearIssue($assigned);
                }
                if ($event === 'order.partial-delivery') {
                    $this->holdForReview($order, $assigned, 'Partial delivery: ' . ($this->reasonText($payload) ?: 'collected ' . ($payload['collected_amount'] ?? '?') . ' Tk'));
                } elseif ($event === 'order.delivered' && Arr::has($payload, 'collected_amount')) {
                    $collected = (float) $payload['collected_amount'];
                    $expected = (float) ($assigned->collectable_amount ?? $order->total ?? 0);
                    if (abs($collected - $expected) >= 1) {
                        $this->holdForReview($order, $assigned, "Collected {$collected} Tk, expected {$expected} Tk");
                    }
                }
                break;
        }

        $assigned->save();
    }

    private function raiseIssue(DeliveryAssignedInfo $assigned, string $flag, string $reason): void
    {
        $assigned->issue_flag = $flag;
        $assigned->issue_reason = mb_substr($reason, 0, 1000);
    }

    private function clearIssue(DeliveryAssignedInfo $assigned): void
    {
        $assigned->issue_flag = null;
        $assigned->issue_reason = null;
    }

    private function holdForReview(Order $order, DeliveryAssignedInfo $assigned, string $reason): void
    {
        if (!$assigned->needs_review) {
            $this->notifyAdmins('Order needs courier review', "Order {$order->order_number}: {$reason}. Settlement is held until you approve it.");
        }
        $assigned->needs_review = true;
        $assigned->review_reason = mb_substr($reason, 0, 255);
    }

    private function applyStatusTransition(Order $order, int $targetStatusId, array $payload, string $source): string
    {
        $currentStatusId = (int) $order->status;
        $previousPaymentStatus = $order->payment_status;

        $blocked = $this->transitions->automaticBlockReason($order, $targetStatusId);
        if ($blocked !== null) {
            return $blocked;
        }

        if (in_array($targetStatusId, [6, 9, 13], true)) {
            $order->payment_status = 'paid';

            if ($order->hasConsignment()) {
                // Real CarryBee logistics: capture fee figures, but settlement only
                // starts on CarryBee's own "order.paid" (handleOrderPaidEvent).
                $deliveryFee = Arr::get($payload, 'delivery_fee');
                $codFee = Arr::get($payload, 'cod_fee');
                if ($deliveryFee !== null) {
                    $order->actual_courier_fee = round((float) $deliveryFee, 2);
                }
                if ($codFee !== null) {
                    $order->actual_cod_fee = round((float) $codFee, 2);
                }
            } else {
                // No consignment means no "paid" webhook will come: settle now.
                $this->orderAccountingService->processOrderDelivery($order, [
                    'actual_courier_fee' => Arr::get($payload, 'delivery_fee'),
                    'actual_cod_fee' => Arr::get($payload, 'cod_fee'),
                    'confirmed' => true,
                    'payout_status' => 'received',
                ]);
            }
        } elseif (in_array($targetStatusId, [7, 8], true)) {
            // Money is reversed only on cancel or when the parcel is back with the vendor
            // (order.returned-to-merchant), never on the first "returning" event (12).
            $this->orderAccountingService->processOrderReversal($order, 'CarryBee delivery returned/cancelled');
        }

        if ($targetStatusId === $currentStatusId) {
            $order->save();

            return 'Order status unchanged';
        }

        $this->transitions->apply($order, $targetStatusId, $source, null, $this->buildHistoryNote($payload));
        $this->notifyWebhookOrderStatusUpdated($order, $targetStatusId);

        if ($targetStatusId === 9 && $previousPaymentStatus !== 'paid') {
            $this->notifyWebhookPaymentCompleted($order);
        }

        return 'Order status updated';
    }

    private function handleOrderPaidEvent(Order $order, CarrybeeWebhookEvent $webhookEvent, array $payload, ?DeliveryAssignedInfo $assigned): void
    {
        $invoiceId = (string) Arr::get($payload, 'invoice_id', '');
        $paidAt = $this->eventTime($payload) ?? now();

        $order->loadMissing(['items.product.vendor', 'vendor']);
        $disbursedTo = $this->hasOwnDeliveryAccount($order) ? 'vendor' : 'platform';
        $previousPaymentStatus = $order->payment_status;

        if ($invoiceId !== '') {
            $webhookEvent->invoice_id = $invoiceId;
            $order->carrybee_invoice_id = $invoiceId;
        }
        $webhookEvent->disbursed_to = $disbursedTo;
        $webhookEvent->admin_acknowledged = false;

        // The authoritative "CarryBee collected the COD and paid it out" signal.
        $order->carrybee_paid_at = $paidAt;
        $order->carrybee_disbursed_to = $disbursedTo;
        $order->courier_payout_status = 'received';
        $order->courier_cost_confirmed = true;
        $order->payment_status = 'paid';
        $order->save();

        if ($assigned) {
            if ($invoiceId !== '') {
                $assigned->carrybee_invoice_id = $invoiceId;
            }
            $assigned->carrybee_paid_at = $paidAt;
            $assigned->transfer_status_id = 10;
            $assigned->save();
        }

        $invoiceDisplay = $invoiceId !== '' ? "ইনভয়েস #{$invoiceId}" : 'ইনভয়েস';
        $disbursedText = $disbursedTo === 'vendor' ? 'ভেন্ডরের নিজস্ব CarryBee অ্যাকাউন্টে' : 'প্ল্যাটফর্ম অ্যাকাউন্টে';

        if ($assigned?->needs_review) {
            // Partial delivery / amount mismatch: record the payment, hold settlement
            // until an admin approves (approveCourierReview).
            $this->notificationService->createAdminNotifications([
                'title' => 'CarryBee paid — settlement held for review',
                'subtitle' => "CarryBee {$invoiceDisplay}: order #{$order->order_number} paid, but it needs review ({$assigned->review_reason}). Approve it to start settlement.",
                'created_by' => null, 'is_seen' => false, 'type' => 'carrybee_paid', 'is_active' => true, 'image' => null, 'module' => 'order',
            ]);

            return;
        }

        $result = $this->triggerDisbursementForPaidOrder($order, [
            'actual_courier_fee' => Arr::get($payload, 'delivery_fee'),
            'actual_cod_fee' => Arr::get($payload, 'cod_fee'),
        ], $previousPaymentStatus);
        $webhookEvent->disbursed_amount = (float) ($result['courier_net_payout'] ?? 0.00);

        $this->notificationService->createAdminNotifications([
            'title'      => 'CarryBee পেমেন্ট পরিশোধ (Order Paid)',
            'subtitle'   => "CarryBee {$invoiceDisplay} দ্বারা অর্ডার #{$order->order_number} এর টাকা {$disbursedText} জমা হয়েছে।",
            'created_by' => null,
            'is_seen'    => false,
            'type'       => 'carrybee_paid',
            'is_active'  => true,
            'image'      => null,
            'module'     => 'order',
        ]);
    }

    /**
     * The single place that starts the disbursement journey once an order is
     * confirmed CarryBee-paid: the webhook, the admin's manual "mark as paid" and
     * the admin's approval of a held review all run this.
     */
    public function triggerDisbursementForPaidOrder(Order $order, array $courierFeeData = [], ?string $previousPaymentStatus = null): array
    {
        $previousPaymentStatus = $previousPaymentStatus ?? $order->payment_status;
        $assigned = $order->deliveryInformation;
        $deliveryFee = $courierFeeData['actual_courier_fee'] ?? $order->actual_courier_fee ?? $assigned?->delivery_fee;
        $codFee = $courierFeeData['actual_cod_fee'] ?? $order->actual_cod_fee ?? $assigned?->cod_fee;
        if ($codFee === null && $assigned?->total_fee !== null && $assigned->delivery_fee !== null
            && (float) $assigned->total_fee >= (float) $assigned->delivery_fee) {
            $codFee = round((float) $assigned->total_fee - (float) $assigned->delivery_fee, 2);
        }
        $courierFeeData['actual_courier_fee'] = $deliveryFee;
        $courierFeeData['actual_cod_fee'] = $codFee;

        $result = $this->orderAccountingService->processOrderDelivery($order, array_merge([
            'confirmed' => true,
            'payout_status' => 'received',
        ], $courierFeeData));

        $this->orderAccountingService->checkAndMarkOrderCompleted($order);

        if ($previousPaymentStatus !== 'paid') {
            $this->notifyWebhookPaymentCompleted($order);
        }

        return $result;
    }

    /**
     * Admin approves a held courier review (partial delivery / amount mismatch).
     * Starts settlement if CarryBee has already paid.
     */
    public function approveCourierReview(Order $order, ?int $adminId, ?string $note = null): array
    {
        return DB::transaction(function () use ($order, $adminId, $note) {
            $order = Order::with('deliveryInformation')->lockForUpdate()->findOrFail($order->id);
            $assigned = $order->deliveryInformation;
            if (!$assigned || !$assigned->needs_review) {
                return ['ok' => false, 'message' => 'This order has no courier review waiting.'];
            }

            $reason = $assigned->review_reason;
            $assigned->needs_review = false;
            $assigned->review_reason = null;
            $assigned->save();

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status_id' => (int) $order->status,
                'note' => 'Courier review approved (' . $reason . ')' . ($note ? ' - ' . $note : ''),
                'changed_by' => $adminId,
                'source' => OrderStatusHistory::SOURCE_MANUAL,
            ]);

            $settlementStarted = false;
            if ((int) $order->status === 10 && $order->is_carrybee_paid) {
                $this->triggerDisbursementForPaidOrder($order);
                $settlementStarted = true;
            }

            return [
                'ok' => true,
                'message' => $settlementStarted ? 'Review approved and settlement started.' : 'Review approved. Settlement will start when CarryBee pays.',
                'settlement_started' => $settlementStarted,
            ];
        });
    }

    public function processTestWebhook(array $payload): array
    {
        $event = (string) ($payload['event'] ?? '');
        $statusMap = (array) config('carrybee.event_status_map', []);
        if (!array_key_exists($event, $statusMap)) {
            return ['ok' => false, 'http_code' => 422, 'message' => 'Unsupported CarryBee event'];
        }

        $order = $this->resolveOrder($payload);

        return [
            'ok' => true,
            'http_code' => 200,
            'message' => 'Webhook preview only. No order, payment or ledger was changed.',
            'data' => [
                'simulation' => true,
                'order_id' => $order?->id,
                'order_matched' => $order !== null,
                'mapped_status_id' => $statusMap[$event],
                'invoice_id' => $payload['invoice_id'] ?? null,
                'settlement_applied' => false,
                'current_settlement_status' => $order ? ($order->is_carrybee_paid ? 'paid' : 'pending') : null,
            ],
        ];
    }

    /* ---------------- helpers ---------------- */

    private function buildFingerprint(array $payload): string
    {
        $key = implode('|', [
            (string) Arr::get($payload, 'event', ''),
            (string) Arr::get($payload, 'store_id', ''),
            (string) Arr::get($payload, 'consignment_id', ''),
            (string) Arr::get($payload, 'merchant_order_id', ''),
            (string) Arr::get($payload, 'timestamptz', ''),
        ]);

        return hash('sha256', $key);
    }

    private function isValidSignature(string $rawBody, string $received): bool
    {
        $secret = (string) config('carrybee.webhook.secret', '');
        if ($secret === '') {
            return false;
        }

        // CarryBee sends the merchant-chosen secret as-is; its docs wrap it in quotes.
        $received = trim($received, " \t\n\r\0\x0B\"'");
        if ($received === '') {
            return false;
        }

        if (str_starts_with($received, 'sha256=')) {
            $received = substr($received, 7);
        }

        if (hash_equals($secret, $received)) {
            return true;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $received);
    }

    private function resolveOrder(array $payload): ?Order
    {
        $orderId = Arr::get($payload, 'order_id');
        if (!empty($orderId) && ($order = Order::find($orderId))) {
            return $order;
        }

        $consignmentId = Arr::get($payload, 'consignment_id');
        if (!empty($consignmentId)) {
            $assigned = DeliveryAssignedInfo::where('consignment_id', $consignmentId)->first();
            if ($assigned?->order_id) {
                return Order::find($assigned->order_id);
            }
        }

        $merchantOrderId = Arr::get($payload, 'merchant_order_id');
        if (!empty($merchantOrderId)) {
            $assigned = DeliveryAssignedInfo::where('merchant_order_id', $merchantOrderId)->first();
            if ($assigned?->order_id) {
                return Order::find($assigned->order_id);
            }

            $draft = CarryBeeOrderCreateForm::where('merchant_order_id', $merchantOrderId)->first();
            if ($draft?->order_id) {
                return Order::find($draft->order_id);
            }

            if ($orderByNumber = Order::where('order_number', $merchantOrderId)->first()) {
                return $orderByNumber;
            }
        }

        return null;
    }

    private function eventTime(array $payload): ?Carbon
    {
        $value = Arr::get($payload, 'timestamptz');
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function hasOwnDeliveryAccount(Order $order): bool
    {
        if ($order->vendor && (bool) $order->vendor->has_own_delivery_account) {
            return true;
        }
        foreach ($order->items as $item) {
            if ($item->product?->vendor?->has_own_delivery_account) {
                return true;
            }
        }

        return false;
    }

    private function reasonText(array $payload): string
    {
        return trim(implode(' - ', array_filter([
            trim((string) Arr::get($payload, 'reason', '')),
            trim((string) Arr::get($payload, 'remarks', '')),
        ])));
    }

    private function describeCauses(array $payload): string
    {
        $parts = [];
        if ($reason = trim((string) Arr::get($payload, 'reason', ''))) {
            $parts[] = $reason;
        }
        foreach ((array) Arr::get($payload, 'causes', []) as $field => $errors) {
            $types = collect((array) $errors)->map(fn ($e) => is_array($e) ? ($e['type'] ?? 'invalid') : (string) $e)->implode(', ');
            $parts[] = "{$field}: {$types}";
        }

        return $parts ? implode('; ', $parts) : 'CarryBee could not create the consignment';
    }

    private function buildHistoryNote(array $payload): string
    {
        $event = (string) Arr::get($payload, 'event', 'unknown');
        $parts = ['CarryBee webhook: ' . $event];

        if (($attempt = Arr::get($payload, 'attempt')) !== null) {
            $parts[] = 'attempt=' . $attempt;
        }
        if (($reason = Arr::get($payload, 'reason')) !== null && $reason !== '') {
            $parts[] = 'reason=' . $reason;
        }
        if (($remarks = Arr::get($payload, 'remarks')) !== null && $remarks !== '') {
            $parts[] = 'remarks=' . $remarks;
        }
        if (($collected = Arr::get($payload, 'collected_amount')) !== null) {
            $parts[] = 'collected=' . $collected;
        }
        if ($event === 'order.create-failed') {
            $parts[] = $this->describeCauses($payload);
        }

        return implode(' | ', $parts);
    }

    /* ---------------- notifications ---------------- */

    private function notifyUser(?int $userId, string $title, string $subtitle): void
    {
        if (!$userId) {
            return;
        }
        $this->notificationService->createNotificationSafely([
            'title' => $title, 'subtitle' => $subtitle, 'created_by' => null, 'send_to' => $userId,
            'is_seen' => false, 'type' => 'order', 'is_active' => true, 'image' => null, 'module' => 'order',
        ]);
    }

    private function notifyVendor(Order $order, string $title, string $subtitle): void
    {
        $order->loadMissing('vendor');
        $this->notifyUser($order->vendor?->user_id, $title, $subtitle);
    }

    private function notifyAdmins(string $title, string $subtitle): void
    {
        $this->notificationService->createAdminNotifications([
            'title' => $title, 'subtitle' => $subtitle, 'created_by' => null,
            'is_seen' => false, 'type' => 'order', 'is_active' => true, 'image' => null, 'module' => 'order',
        ]);
    }

    private function notifyCreateFailed(Order $order, array $payload): void
    {
        $reason = $this->describeCauses($payload);
        $this->notifyAdmins('CarryBee consignment failed', "Order {$order->order_number}: {$reason}");
        $this->notifyVendor($order, 'CarryBee consignment failed', "Order {$order->order_number} could not be booked with CarryBee: {$reason}");
    }

    private function notifyWebhookOrderStatusUpdated(Order $order, int $statusId): void
    {
        $order->loadMissing('vendor.user');
        $statusName = OrderStatus::where('id', $statusId)->value('name') ?: 'Status ' . $statusId;

        $this->notifyUser($order->user_id, 'Order Status Updated', "CarryBee updated order {$order->order_number} to {$statusName}.");
        if ($order->vendor?->user_id) {
            $this->notifyUser($order->vendor->user_id, 'Vendor Order Status Updated', "CarryBee updated order {$order->order_number} to {$statusName}.");
        }
        $this->notifyAdmins('CarryBee Order Status Updated', "Order {$order->order_number} updated to {$statusName}.");
    }

    private function notifyWebhookPaymentCompleted(Order $order): void
    {
        $amount = number_format((float) $order->total, 2, '.', '');
        $subtitle = "CarryBee marked payment collected for order {$order->order_number}. Amount {$amount} BDT.";

        $this->notificationService->createNotificationSafely([
            'title' => 'Order Payment Completed', 'subtitle' => $subtitle, 'created_by' => null, 'send_to' => $order->user_id,
            'is_seen' => false, 'type' => 'payment', 'is_active' => true, 'image' => null, 'module' => 'order',
        ]);
        $this->notificationService->createAdminNotifications([
            'title' => 'Order Payment Completed', 'subtitle' => $subtitle, 'created_by' => null,
            'is_seen' => false, 'type' => 'payment', 'is_active' => true, 'image' => null, 'module' => 'order',
        ]);
    }
}
