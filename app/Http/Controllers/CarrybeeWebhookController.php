<?php

namespace App\Http\Controllers;

use App\Models\CarrybeeWebhookEvent;
use App\Service\CarrybeeWebhookService;
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
}
