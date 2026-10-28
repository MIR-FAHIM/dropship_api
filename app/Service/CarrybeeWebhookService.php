<?php

namespace App\Service;

use App\Models\CarryBeeOrderCreateForm;
use App\Models\CarrybeeWebhookEvent;
use App\Models\DeliveryAssignedInfo;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrderStatusHistory;
use App\Models\Transaction;
use Illuminate\Support\Arr;

class CarrybeeWebhookService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly OrderAccountingService $orderAccountingService
    ) {
    }

    public function process(array $payload, string $rawBody, string $signature, array $headers = [], bool $isTest = false): array
    {
        $event = (string) ($payload['event'] ?? '');
        if ($event === '') {
            return [
                'ok' => false,
                'http_code' => 422,
                'message' => 'Invalid payload: event is required',
            ];
        }

        $fingerprint = $this->buildFingerprint($payload);
        $webhookEvent = CarrybeeWebhookEvent::where('fingerprint', $fingerprint)->first();

        if ($webhookEvent) {
            $webhookEvent->attempts = (int) $webhookEvent->attempts + 1;
            if (Arr::has($payload, 'invoice_id')) {
                $webhookEvent->invoice_id = Arr::get($payload, 'invoice_id');
            }

            if ($webhookEvent->processed_at !== null) {
                $webhookEvent->processing_status = 'duplicate';
                $webhookEvent->message = 'Duplicate webhook ignored';
                $webhookEvent->save();

                return [
                    'ok' => true,
                    'http_code' => 200,
                    'message' => 'Duplicate webhook ignored',
                    'data' => ['event_id' => $webhookEvent->id],
                ];
            }
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

        $signatureValid = $isTest || $this->isValidSignature($rawBody, $signature);
        $webhookEvent->signature_valid = $signatureValid;

        if (!$signatureValid) {
            $webhookEvent->processing_status = 'invalid_signature';
            $webhookEvent->message = 'Signature verification failed';
            $webhookEvent->processed_at = now();
            $webhookEvent->save();

            return [
                'ok' => false,
                'http_code' => 401,
                'message' => 'Invalid webhook signature',
            ];
        }

        $eventStatusMap = (array) config('carrybee.event_status_map', []);
        $mappedStatusId = $eventStatusMap[$event] ?? null;
        if (!array_key_exists($event, $eventStatusMap)) {
            $webhookEvent->processing_status = 'ignored';
            $webhookEvent->message = 'Unsupported event: ' . $event;
            $webhookEvent->processed_at = now();
            $webhookEvent->save();

            return [
                'ok' => true,
                'http_code' => 200,
                'message' => 'Event ignored (unsupported)',
                'data' => ['event_id' => $webhookEvent->id],
            ];
        }

        $order = $this->resolveOrder($payload);
        if (!$order) {
            $webhookEvent->processing_status = 'ignored';
            $webhookEvent->message = 'Order not found for webhook identifiers';
            $webhookEvent->processed_at = now();
            $webhookEvent->save();

            return [
                'ok' => true,
                'http_code' => 202,
                'message' => 'Order not found, event stored',
                'data' => ['event_id' => $webhookEvent->id],
            ];
        }

        $webhookEvent->order_id = $order->id;
        $webhookEvent->mapped_status_id = $mappedStatusId;

        // Specific processing for CarryBee order.paid event
        if ($event === 'order.paid') {
            $this->handleOrderPaidEvent($order, $webhookEvent, $payload);
        }

        $statusMessage = 'No status mutation required';
        if ($mappedStatusId !== null) {
            $statusMessage = $this->applyStatusTransition($order, (int) $mappedStatusId, $payload);
        }

        $this->syncDeliveryAssignmentData($order, $payload, $mappedStatusId);

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
            // If secret is not set yet, allow webhook processing
            return true;
        }

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

        $computed = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($computed, $received);
    }

    private function resolveOrder(array $payload): ?Order
    {
        $orderId = Arr::get($payload, 'order_id');
        if (!empty($orderId)) {
            $order = Order::find($orderId);
            if ($order) {
                return $order;
            }
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

            // Fallback: match directly with order_number
            $orderByNumber = Order::where('order_number', $merchantOrderId)->first();
            if ($orderByNumber) {
                return $orderByNumber;
            }
        }

        return null;
    }

    private function applyStatusTransition(Order $order, int $targetStatusId, array $payload): string
    {
        $currentStatusId = (int) $order->status;
        $previousPaymentStatus = $order->payment_status;
        $rankMap = (array) config('carrybee.status_rank_map', []);
        $currentRank = (int) ($rankMap[$currentStatusId] ?? $currentStatusId);
        $targetRank = (int) ($rankMap[$targetStatusId] ?? $targetStatusId);

        if ($targetRank < $currentRank) {
            return 'Skipped status regression';
        }

        if ($targetStatusId !== $currentStatusId) {
            $order->status = $targetStatusId;
        }

        if (in_array($targetStatusId, [6, 9])) {
            $order->payment_status = 'paid';
            $courierFeeData = [
                'actual_courier_fee' => Arr::get($payload, 'delivery_fee', null),
                'actual_cod_fee' => Arr::get($payload, 'cod_fee', 0),
                'confirmed' => true,
                'payout_status' => 'received',
            ];
            $this->orderAccountingService->processOrderDelivery($order, $courierFeeData);
        } elseif (in_array($targetStatusId, [7, 8, 10, 11])) {
            $this->orderAccountingService->processOrderReversal($order, 'CarryBee delivery returned/cancelled');
        }

        $order->save();

        if ($targetStatusId !== $currentStatusId) {
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status_id' => $targetStatusId,
                'note' => $this->buildHistoryNote($payload),
                'changed_by' => null,
            ]);

            $this->notifyWebhookOrderStatusUpdated($order, $targetStatusId);
        }

        if ($targetStatusId === 9 && $previousPaymentStatus !== 'paid') {
            $this->notifyWebhookPaymentCompleted($order);
        }

        if ($targetStatusId !== $currentStatusId) {
            return 'Order status updated';
        }

        return 'Order status unchanged';
    }

    private function notifyWebhookOrderStatusUpdated(Order $order, int $statusId): void
    {
        $order->loadMissing('vendor.user');
        $statusName = OrderStatus::where('id', $statusId)->value('name') ?: 'Status ' . $statusId;

        $this->notificationService->createNotificationSafely([
            'title'      => 'Order Status Updated',
            'subtitle'   => "CarryBee updated order {$order->order_number} to {$statusName}.",
            'created_by' => null,
            'send_to'    => $order->user_id,
            'is_seen'    => false,
            'type'       => 'order',
            'is_active'  => true,
            'image'      => null,
            'module'     => 'order',
        ]);

        if ($order->vendor?->user_id) {
            $this->notificationService->createNotificationSafely([
                'title'      => 'Vendor Order Status Updated',
                'subtitle'   => "CarryBee updated order {$order->order_number} to {$statusName}.",
                'created_by' => null,
                'send_to'    => $order->vendor->user_id,
                'is_seen'    => false,
                'type'       => 'order',
                'is_active'  => true,
                'image'      => null,
                'module'     => 'order',
            ]);
        }

        $this->notificationService->createAdminNotifications([
            'title'      => 'CarryBee Order Status Updated',
            'subtitle'   => "Order {$order->order_number} updated to {$statusName}.",
            'created_by' => null,
            'is_seen'    => false,
            'type'       => 'order',
            'is_active'  => true,
            'image'      => null,
            'module'     => 'order',
        ]);
    }

    private function notifyWebhookPaymentCompleted(Order $order): void
    {
        $amount = number_format((float) $order->total, 2, '.', '');

        $this->notificationService->createNotificationSafely([
            'title'      => 'Order Payment Completed',
            'subtitle'   => "CarryBee marked payment collected for order {$order->order_number}. Amount {$amount} BDT.",
            'created_by' => null,
            'send_to'    => $order->user_id,
            'is_seen'    => false,
            'type'       => 'payment',
            'is_active'  => true,
            'image'      => null,
            'module'     => 'order',
        ]);

        $this->notificationService->createAdminNotifications([
            'title'      => 'Order Payment Completed',
            'subtitle'   => "CarryBee marked payment collected for order {$order->order_number}. Amount {$amount} BDT.",
            'created_by' => null,
            'is_seen'    => false,
            'type'       => 'payment',
            'is_active'  => true,
            'image'      => null,
            'module'     => 'order',
        ]);
    }

    private function ensureCreditTransactionForCompletedOrder(Order $order): void
    {
        $hasCredit = Transaction::where('order_id', $order->id)
            ->where('trx_type', 'credit')
            ->where('type', 'order_status')
            ->exists();

        if ($hasCredit) {
            return;
        }

        Transaction::create([
            'amount' => $order->total,
            'trx_type' => 'credit',
            'status' => 'completed',
            'source' => 'cod',
            'order_id' => $order->id,
            'type' => 'order_status',
            'note' => 'Credit transaction for order #' . $order->order_number,
        ]);
    }

    private function syncDeliveryAssignmentData(Order $order, array $payload, ?int $mappedStatusId): void
    {
        $assigned = DeliveryAssignedInfo::where('order_id', $order->id)->first();
        if (!$assigned) {
            return;
        }

        if (Arr::has($payload, 'collectable_amount')) {
            $assigned->collectable_amount = (float) Arr::get($payload, 'collectable_amount', 0);
        }

        if (Arr::has($payload, 'delivery_fee')) {
            $assigned->delivery_fee = (float) Arr::get($payload, 'delivery_fee', 0);
        }

        if (Arr::has($payload, 'total_fee')) {
            $assigned->total_fee = (float) Arr::get($payload, 'total_fee', 0);
        }

        if ($mappedStatusId !== null) {
            $assigned->transfer_status_id = $mappedStatusId;
        }

        $assigned->save();
    }

    private function buildHistoryNote(array $payload): string
    {
        $event = (string) Arr::get($payload, 'event', 'unknown');
        $attempt = Arr::get($payload, 'attempt');
        $reason = Arr::get($payload, 'reason');
        $remarks = Arr::get($payload, 'remarks');

        $parts = ['CarryBee webhook: ' . $event];

        if ($attempt !== null) {
            $parts[] = 'attempt=' . $attempt;
        }

        if ($reason !== null && $reason !== '') {
            $parts[] = 'reason=' . $reason;
        }

        if ($remarks !== null && $remarks !== '') {
            $parts[] = 'remarks=' . $remarks;
        }

        return implode(' | ', $parts);
    }

    private function handleOrderPaidEvent(Order $order, CarrybeeWebhookEvent $webhookEvent, array $payload): void
    {
        $invoiceId = (string) Arr::get($payload, 'invoice_id', '');
        $timestamptz = Arr::get($payload, 'timestamptz');
        $paidAt = $timestamptz ? \Carbon\Carbon::parse($timestamptz) : now();

        $order->loadMissing(['items.product.vendor', 'vendor']);

        // Determine if disbursement went to vendor or platform
        $hasOwnDeliveryAccount = false;
        if ($order->vendor && (bool) $order->vendor->has_own_delivery_account) {
            $hasOwnDeliveryAccount = true;
        } else {
            foreach ($order->items as $item) {
                if ($item->product?->vendor?->has_own_delivery_account) {
                    $hasOwnDeliveryAccount = true;
                    break;
                }
            }
        }

        $disbursedTo = $hasOwnDeliveryAccount ? 'vendor' : 'platform';

        // Disbursed net amount from CarryBee
        $remainingCod = (float) ($order->remaining_cod ?? max(0, (float) $order->total - (float) ($order->advance_amount ?? 0)));
        $actualCourierCost = (float) ($order->actual_courier_cost ?? ((float) ($order->actual_courier_fee ?? 0) + (float) ($order->actual_cod_fee ?? 0)));
        $netPayout = max(0.00, round($remainingCod - $actualCourierCost, 2));

        // Update Webhook Event
        if ($invoiceId !== '') {
            $webhookEvent->invoice_id = $invoiceId;
        }
        $webhookEvent->disbursed_to = $disbursedTo;
        $webhookEvent->disbursed_amount = $netPayout;
        $webhookEvent->admin_acknowledged = false;

        // Update Order
        if ($invoiceId !== '') {
            $order->carrybee_invoice_id = $invoiceId;
        }
        $order->carrybee_paid_at = $paidAt;
        $order->carrybee_disbursed_to = $disbursedTo;
        $order->courier_payout_status = 'received';
        $order->courier_cost_confirmed = true;
        $order->save();

        // Update DeliveryAssignedInfo
        $assigned = DeliveryAssignedInfo::where('order_id', $order->id)->first();
        if ($assigned) {
            if ($invoiceId !== '') {
                $assigned->carrybee_invoice_id = $invoiceId;
            }
            $assigned->carrybee_paid_at = $paidAt;
            $assigned->transfer_status_id = 9;
            $assigned->save();
        }

        // Notify Admins
        $disbursedText = $disbursedTo === 'vendor' ? 'ভেন্ডরের নিজস্ব CarryBee অ্যাকাউন্টে' : 'প্ল্যাটফর্ম অ্যাকাউন্টে';
        $invoiceDisplay = $invoiceId !== '' ? "ইনভয়েস #{$invoiceId}" : 'ইনভয়েস';
        $this->notificationService->createAdminNotifications([
            'title'      => 'CarryBee পেমেন্ট পরিশোধ (Order Paid)',
            'subtitle'   => "CarryBee {$invoiceDisplay} দ্বারা অর্ডার #{$order->order_number} এর টাকা {$disbursedText} জমা হয়েছে।",
            'created_by' => null,
            'is_seen'    => false,
            'type'       => 'carrybee_paid',
            'is_active'  => true,
            'image'      => null,
            'module'     => 'order',
        ]);
    }

    public function processTestWebhook(array $payload): array
    {
        $rawBody = json_encode($payload);
        return $this->process(
            payload: $payload,
            rawBody: $rawBody,
            signature: 'test-simulated',
            headers: ['X-Simulated-By' => ['Admin-Panel-Tester']],
            isTest: true
        );
    }
}
