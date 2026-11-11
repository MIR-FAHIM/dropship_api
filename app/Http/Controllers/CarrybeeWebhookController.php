<?php

namespace App\Http\Controllers;

use App\Models\CarrybeeWebhookEvent;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Service\CarrybeeWebhookService;
use App\Service\OrderAccountingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CarrybeeWebhookController extends Controller
{
    public function __construct(
        private readonly CarrybeeWebhookService $webhookService,
        private readonly OrderAccountingService $orderAccountingService
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        if (!is_array($payload) || empty($payload)) {
            $payload = $request->all();
        }

        if (!is_array($payload)) {
            $payload = [];
        }

        $rawBody = $request->getContent();
        $signatureHeader = (string) config('carrybee.webhook.signature_header', 'X-Carrybee-Webhook-Signature');
        $signature = (string) $request->header($signatureHeader, '');

        $result = $this->webhookService->process(
            payload: $payload,
            rawBody: $rawBody,
            signature: $signature,
            headers: $request->headers->all(),
        );

        return response()->json([
            'status' => $result['ok'] ? 'success' : 'failed',
            'message' => $result['message'],
            'data' => $result['data'] ?? null,
        ], $result['http_code']);
    }

    /**
     * Get all unacknowledged CarryBee order.paid events for the Admin Dashboard modal.
     */
    public function getUnacknowledgedPaidOrders(): JsonResponse
    {
        $events = CarrybeeWebhookEvent::where('event', 'order.paid')
            ->whereIn('processing_status', ['processed', 'duplicate'])
            ->whereNotNull('disbursed_to')
            ->where('admin_acknowledged', false)
            ->whereNotNull('order_id')
            ->with(['order.vendor', 'order.items.product.vendor'])
            ->latest()
            ->limit(30)
            ->get();

        $formatted = $events->map(function (CarrybeeWebhookEvent $event) {
            $order = $event->order;
            $vendor = $order?->vendor ?: $order?->items?->first()?->product?->vendor;

            return [
                'event_id'            => $event->id,
                'order_id'            => $order?->id,
                'order_number'        => $order?->order_number,
                'consignment_id'      => $event->consignment_id,
                'invoice_id'          => $event->invoice_id,
                'disbursed_to'        => $event->disbursed_to ?: ($vendor?->has_own_delivery_account ? 'vendor' : 'platform'),
                'disbursed_amount'    => (float) ($event->disbursed_amount ?? $order?->courier_net_payout ?? 0),
                'total'               => (float) ($order?->total ?? 0),
                'advance_amount'      => (float) ($order?->advance_amount ?? 0),
                'remaining_cod'       => (float) ($order?->remaining_cod ?? 0),
                'actual_courier_fee'  => (float) ($order?->actual_courier_fee ?? 0),
                'actual_cod_fee'      => (float) ($order?->actual_cod_fee ?? 0),
                'actual_courier_cost' => (float) ($order?->actual_courier_cost ?? 0),
                'customer_name'       => $order?->customer_name,
                'customer_phone'      => $order?->customer_phone,
                'event_time'          => $event->event_time ?: $event->created_at,
                'created_at'          => $event->created_at,
                'vendor'              => $vendor ? [
                    'id'                       => $vendor->id,
                    'shop_name'                => $vendor->shop_name,
                    'has_own_delivery_account' => (bool) $vendor->has_own_delivery_account,
                ] : null,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data'   => $formatted,
        ]);
    }

    /**
     * Mark an order.paid alert as acknowledged by the admin.
     */
    public function acknowledgePaidOrder(int $id): JsonResponse
    {
        $event = CarrybeeWebhookEvent::findOrFail($id);
        $event->admin_acknowledged = true;
        $event->admin_acknowledged_at = now();
        $event->save();

        return response()->json([
            'status'  => 'success',
            'message' => 'CarryBee payment notification acknowledged successfully.',
            'data'    => [
                'event_id'           => $event->id,
                'admin_acknowledged' => true,
            ],
        ]);
    }

    /**
     * Testing simulation endpoint for Admin Webhook Testing Board.
     */
    public function testWebhook(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        if (!is_array($payload) || empty($payload)) {
            $payload = $request->all();
        }

        if (!is_array($payload) || empty($payload['event'])) {
            return response()->json([
                'status'  => 'failed',
                'message' => 'Event name is required (e.g. order.paid)',
            ], 422);
        }

        // Fill timestamptz if omitted
        if (empty($payload['timestamptz'])) {
            $payload['timestamptz'] = now()->toIso8601String();
        }

        $result = $this->webhookService->processTestWebhook($payload);

        return response()->json([
            'status'  => $result['ok'] ? 'success' : 'failed',
            'message' => $result['message'],
            'data'    => $result['data'] ?? null,
        ], $result['http_code']);
    }

    /**
     * Fetch recent webhook events list for testing board.
     */
    public function getWebhookEvents(Request $request): JsonResponse
    {
        $query = CarrybeeWebhookEvent::with(['order.vendor'])->latest();

        if ($request->filled('event')) {
            $query->where('event', $request->get('event'));
        }

        if ($request->filled('consignment_id')) {
            $query->where('consignment_id', 'like', '%' . $request->get('consignment_id') . '%');
        }

        $limit = (int) $request->get('limit', 25);
        $events = $query->paginate($limit);

        return response()->json([
            'status' => 'success',
            'data'   => $events,
        ]);
    }

    /**
     * Re-run a stored event that failed or found no order (e.g. after fixing a consignment link).
     * POST /api/delivery-companies/carrybee/webhook-events/{id}/reprocess
     */
    public function reprocessEvent(int $id): JsonResponse
    {
        $event = CarrybeeWebhookEvent::find($id);
        if (!$event) {
            return response()->json(['status' => 'failed', 'message' => 'Webhook event not found.'], 404);
        }
        if ($event->processing_status === 'processed') {
            return response()->json(['status' => 'failed', 'message' => 'This event was already processed.'], 422);
        }
        // A rejected event failed the signature check: its payload may not be from CarryBee.
        if ($event->processing_status === 'rejected' || !$event->signature_valid) {
            return response()->json(['status' => 'failed', 'message' => 'This event failed the signature check and cannot be trusted. Fix the secret so CarryBee resends it, or run "Sync from CarryBee".'], 422);
        }
        if (!is_array($event->payload) || empty($event->payload['event'])) {
            return response()->json(['status' => 'failed', 'message' => 'This event has no usable payload.'], 422);
        }

        // Clear the "done" marker so the normal pipeline runs it again.
        $event->forceFill(['processed_at' => null, 'processing_status' => 'received', 'signature_valid' => true])->save();
        $result = $this->webhookService->processTrusted($event->payload, OrderStatusHistory::SOURCE_WEBHOOK);

        return response()->json([
            'status' => $result['ok'] ? 'success' : 'failed',
            'message' => $result['message'],
            'data' => $result['data'] ?? null,
        ], $result['ok'] ? 200 : $result['http_code']);
    }

    /**
     * Per-store webhook health for the last N days: is each CarryBee account sending us events?
     * GET /api/delivery-companies/carrybee/webhook-health?days=7
     */
    public function webhookHealth(Request $request): JsonResponse
    {
        $days = max(1, min(90, (int) $request->get('days', 7)));
        $since = now()->subDays($days);

        $rows = CarrybeeWebhookEvent::query()
            ->where('created_at', '>=', $since)
            ->selectRaw("COALESCE(store_id, '') as store_id, processing_status, COUNT(*) as total, MAX(created_at) as last_seen")
            ->groupBy('store_id', 'processing_status')
            ->get();

        $byStore = [];
        foreach ($rows as $row) {
            $key = (string) $row->store_id;
            $byStore[$key] ??= ['store_id' => $key ?: null, 'total' => 0, 'by_status' => [], 'last_seen' => null];
            $byStore[$key]['total'] += (int) $row->total;
            $byStore[$key]['by_status'][$row->processing_status] = (int) $row->total;
            if ($byStore[$key]['last_seen'] === null || $row->last_seen > $byStore[$key]['last_seen']) {
                $byStore[$key]['last_seen'] = $row->last_seen;
            }
        }

        // Vendors that should be sending events from their own CarryBee account.
        $vendors = \App\Models\Vendor::where('has_own_delivery_account', true)
            ->get(['id', 'shop_name', 'carryb_store_id']);
        $vendorHealth = $vendors->map(function ($vendor) use ($byStore) {
            $stats = $vendor->carryb_store_id !== null ? ($byStore[(string) $vendor->carryb_store_id] ?? null) : null;

            return [
                'vendor_id' => $vendor->id,
                'shop_name' => $vendor->shop_name,
                'carryb_store_id' => $vendor->carryb_store_id,
                'events' => $stats['total'] ?? 0,
                'last_seen' => $stats['last_seen'] ?? null,
                'by_status' => $stats['by_status'] ?? [],
                'healthy' => ($stats['total'] ?? 0) > 0 && (($stats['by_status']['rejected'] ?? 0) === 0),
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'days' => $days,
                'stores' => array_values($byStore),
                'own_account_vendors' => $vendorHealth,
                'secret_configured' => (string) config('carrybee.webhook.secret', '') !== '',
            ],
        ]);
    }

    /**
     * Run the catch-up now (all quiet consignments, or one consignment).
     * POST /api/delivery-companies/carrybee/catch-up {consignment_id?}
     */
    public function runCatchUp(Request $request, \App\Service\CarrybeeCatchUpService $catchUp): JsonResponse
    {
        $validated = $request->validate([
            'consignment_id' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $summary = $catchUp->run($validated['limit'] ?? 20, $validated['consignment_id'] ?? null);

        return response()->json(['status' => 'success', 'message' => "Checked {$summary['checked']}, updated {$summary['updated']}.", 'data' => $summary]);
    }

    /**
     * Manually mark or reset an order's CarryBee payment status.
     * POST /api/delivery-companies/carrybee/manual-order-payment/{id}
     */
    public function manualOrderPayment(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:mark_paid,reset'],
            'invoice_id' => ['nullable', 'string', 'max:100'],
            'disbursed_to' => ['nullable', 'string', 'in:vendor,platform'],
            'paid_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($request, $id, $validated) {
            $order = Order::with(['deliveryInformation', 'vendor', 'items.product.vendor'])->lockForUpdate()->find($id);
            if (!$order) {
                return response()->json(['status' => 'failed', 'message' => 'Order not found.'], 404);
            }

            return $this->applyManualOrderPayment($request, $order, $validated);
        });
    }

    private function applyManualOrderPayment(Request $request, Order $order, array $validated): JsonResponse
    {
        $action = $validated['action'];

        if ($action === 'mark_paid') {
            if (in_array((int) $order->status, Order::CARRYBEE_NON_PAYABLE_STATUSES, true)) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Cancelled, returned, or lost orders cannot be marked as CarryBee paid.',
                ], 422);
            }

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

            $disbursedTo = $validated['disbursed_to'] ?? ($hasOwnDeliveryAccount ? 'vendor' : 'platform');
            $paidAt = !empty($validated['paid_at']) ? Carbon::parse($validated['paid_at']) : now();
            $invoiceId = !empty($validated['invoice_id']) ? trim($validated['invoice_id']) : ($order->carrybee_invoice_id ?: 'MANUAL-' . strtoupper(uniqid()));

            $order->carrybee_invoice_id = $invoiceId;
            $order->carrybee_paid_at = $paidAt;
            $order->carrybee_disbursed_to = $disbursedTo;
            $order->courier_payout_status = 'received';
            $order->payment_status = 'paid';
            $order->save();

            // Sync deliveryInformation if present
            if ($order->deliveryInformation) {
                $order->deliveryInformation->carrybee_invoice_id = $invoiceId;
                $order->deliveryInformation->carrybee_paid_at = $paidAt;
                $order->deliveryInformation->save();
            }

            // This manual reconciliation action IS a "paid" confirmation, same as
            // the real webhook - it must start the same disbursement journey, not
            // just flip the paid flags.
            $disbursement = $this->webhookService->triggerDisbursementForPaidOrder($order);
            $order->courier_net_payout = $disbursement['courier_net_payout'] ?? $order->courier_net_payout;

            // Status history log
            $adminUser = $request->user() ?: $request->attributes->get('api_user');

            // Create/update a webhook event record so it shows in testing & webhook logs
            CarrybeeWebhookEvent::create([
                'order_id' => $order->id,
                'event' => 'order.paid',
                'fingerprint' => substr('manual-' . $order->id . '-' . uniqid() . '-' . bin2hex(random_bytes(4)), 0, 64),
                'consignment_id' => $order->consignment_id ?: ($order->deliveryInformation?->consignment_id ?: 'MANUAL-' . $order->order_number),
                'invoice_id' => $invoiceId,
                'disbursed_to' => $disbursedTo,
                'disbursed_amount' => (float) ($order->courier_net_payout ?? 0),
                'payload' => [
                    'source' => 'manual_admin_action',
                    'note' => $validated['note'] ?? null,
                    'marked_by' => $adminUser?->id,
                ],
                'processing_status' => 'processed',
                'admin_acknowledged' => true,
                'admin_acknowledged_at' => now(),
            ]);

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status_id' => (int) $order->status,
                'note' => 'Manual Action: Marked as CarryBee Paid (Invoice: ' . $invoiceId . ', Disbursed To: ' . $disbursedTo . ')' . (!empty($validated['note']) ? ' - ' . $validated['note'] : ''),
                'changed_by' => $adminUser?->id,
                'source' => OrderStatusHistory::SOURCE_MANUAL,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Order #{$order->order_number} successfully marked as CarryBee Paid.",
                'data' => $order->fresh(['deliveryInformation', 'orderStatus']),
            ]);
        }

        if ($action === 'reset') {
            // Undo any settlement/ledger entries the (now-mistaken) "mark paid"
            // action may have started, so resetting never leaves a disbursement
            // journey orphaned - the inverse of triggerDisbursementForPaidOrder().
            $this->orderAccountingService->processOrderReversal($order, 'Admin manually reset CarryBee paid status');

            $order->carrybee_invoice_id = null;
            $order->carrybee_paid_at = null;
            $order->carrybee_disbursed_to = null;
            $order->courier_payout_status = 'pending';

            // A reset moves a CarryBee Paid order back to Delivered; delivery and payout are separate.
            if ((int) $order->status === (int) config('carrybee.event_status_map.order.paid', 10)) {
                $order->status = 6; // Delivered
            } elseif (in_array((int) $order->status, Order::CARRYBEE_NON_PAYABLE_STATUSES, true)) {
                $order->payment_status = 'unpaid';
            }
            $order->save();

            if ($order->deliveryInformation) {
                $order->deliveryInformation->carrybee_invoice_id = null;
                $order->deliveryInformation->carrybee_paid_at = null;
                $order->deliveryInformation->save();
            }

            $adminUser = $request->user() ?: $request->attributes->get('api_user');
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status_id' => (int) $order->status,
                'note' => 'Manual Action: Reset CarryBee Paid status back to unpaid/pending' . (!empty($validated['note']) ? ' - ' . $validated['note'] : ''),
                'changed_by' => $adminUser?->id,
                'source' => OrderStatusHistory::SOURCE_MANUAL,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "CarryBee payment status for Order #{$order->order_number} has been reset.",
                'data' => $order->fresh(['deliveryInformation', 'orderStatus']),
            ]);
        }

        return response()->json(['status' => 'failed', 'message' => 'Invalid action.'], 400);
    }
}
