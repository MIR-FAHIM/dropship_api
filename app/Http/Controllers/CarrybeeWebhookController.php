<?php

namespace App\Http\Controllers;

use App\Models\CarrybeeWebhookEvent;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Service\CarrybeeWebhookService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CarrybeeWebhookController extends Controller
{
    public function __construct(private readonly CarrybeeWebhookService $webhookService)
    {
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
     * Manually mark or reset an order's CarryBee payment status.
     * POST /api/delivery-companies/carrybee/manual-order-payment/{id}
     */
    public function manualOrderPayment(Request $request, int $id): JsonResponse
    {
        $order = Order::with(['deliveryInformation', 'vendor', 'items.product.vendor'])->find($id);
        if (!$order) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Order not found.',
            ], 404);
        }

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:mark_paid,reset'],
            'invoice_id' => ['nullable', 'string', 'max:100'],
            'disbursed_to' => ['nullable', 'string', 'in:vendor,platform'],
            'paid_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $action = $validated['action'];

        if ($action === 'mark_paid') {
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
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Order #{$order->order_number} successfully marked as CarryBee Paid.",
                'data' => $order->fresh(['deliveryInformation', 'orderStatus']),
            ]);
        }

        if ($action === 'reset') {
            $order->carrybee_invoice_id = null;
            $order->carrybee_paid_at = null;
            $order->carrybee_disbursed_to = null;
            $order->courier_payout_status = 'pending';

            // If the order is cancelled/returned, ensure payment_status is unpaid
            if (in_array((int) $order->status, [7, 8, 10, 11])) {
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
