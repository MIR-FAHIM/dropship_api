<?php

namespace App\Service;

use App\Models\DeliveryAssignedInfo;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Catches up on CarryBee events we never received. For active consignments that
 * have been quiet for `carrybee.catch_up.quiet_hours`, it asks CarryBee's order
 * details API for the current state and runs the result through the same
 * webhook processing (source "catch-up"), so all journey rules still apply.
 */
class CarrybeeCatchUpService
{
    /** CarryBee payment_status values that mean the COD was paid out (compared lower-cased, exact). */
    private const PAID_PAYMENT_STATUSES = ['paid'];

    private bool $dryRun = false;

    public function __construct(
        private readonly CarrybeeCredentialResolver $credentials,
        private readonly CarrybeeWebhookService $webhooks,
    ) {
    }

    /**
     * @return array{checked: int, updated: int, failed: int, details: array<int, array>}
     */
    public function run(?int $limit = null, ?string $consignmentId = null, bool $dryRun = false): array
    {
        $this->dryRun = $dryRun;
        $quietSince = now()->subHours(max(1, (int) config('carrybee.catch_up.quiet_hours', 6)));
        $activeStatuses = (array) config('carrybee.catch_up.active_statuses', [3, 4, 5, 6, 12, 13]);

        $query = DeliveryAssignedInfo::query()
            ->whereNotNull('consignment_id')
            ->whereRaw("TRIM(consignment_id) <> ''");

        if ($consignmentId) {
            // A named consignment is looked up whatever its status (useful with --dry-run).
            $query->where('consignment_id', $consignmentId);
        } else {
            // Still moving, or marked Carrybee Paid / Completed by hand without CarryBee's invoice.
            $query->whereHas('order', fn ($q) => $q->whereIn('status', array_map('strval', $activeStatuses))
                ->orWhere(fn ($paid) => $paid->whereIn('status', ['9', '10'])
                    ->where(fn ($noInvoice) => $noInvoice->whereNull('carrybee_invoice_id')->orWhere('carrybee_invoice_id', ''))));
            $query->where(fn ($q) => $q->whereNull('courier_stage_at')->orWhere('courier_stage_at', '<', $quietSince))
                ->where(fn ($q) => $q->whereNull('courier_checked_at')->orWhere('courier_checked_at', '<', $quietSince));
        }

        $rows = $query->orderByRaw('courier_checked_at IS NOT NULL, courier_checked_at')
            ->limit($limit ?? (int) config('carrybee.catch_up.batch_size', 50))
            ->get();

        $summary = ['checked' => 0, 'updated' => 0, 'failed' => 0, 'details' => []];

        foreach ($rows as $assigned) {
            $summary['checked']++;
            try {
                $result = $this->checkOne($assigned);
                if ($result['updated']) {
                    $summary['updated']++;
                }
                $summary['details'][] = $result;
            } catch (Throwable $e) {
                report($e);
                $summary['failed']++;
                $summary['details'][] = ['consignment_id' => $assigned->consignment_id, 'updated' => false, 'error' => $e->getMessage()];
            }
        }

        return $summary;
    }

    private function checkOne(DeliveryAssignedInfo $assigned): array
    {
        $order = Order::find($assigned->order_id);
        $base = ['consignment_id' => $assigned->consignment_id, 'order_id' => $assigned->order_id, 'updated' => false];

        $resolved = $this->credentials->forVendor($order?->vendor_id);
        if (!$this->dryRun) {
            // Mark as checked either way so a broken account doesn't block the queue.
            $assigned->forceFill(['courier_checked_at' => now()])->save();
        }

        if (!$resolved['service']) {
            return $base + ['error' => $resolved['error']];
        }

        $response = $resolved['service']->getOrderDetails($assigned->consignment_id);
        if (($response['status'] ?? 500) >= 400 || !empty($response['body']['error'])) {
            return $base + ['error' => 'CarryBee ' . ($response['status'] ?? '?') . ': ' . ($response['body']['message'] ?? 'request failed')];
        }

        $data = (array) Arr::get($response, 'body.data', []);
        $payloads = $this->payloadsFromDetails($data, $assigned);

        if ($this->dryRun) {
            // Show CarryBee's raw answer and what we would do, without changing anything.
            return $base + ['events' => [
                'order ' . ($order?->order_number ?? '?') . ' status ' . ($order?->status ?? '?'),
                'CarryBee transfer_status=' . json_encode($data['transfer_status'] ?? null)
                    . ' payment_status=' . json_encode($data['payment_status'] ?? null)
                    . ' invoice_id=' . json_encode($data['invoice_id'] ?? null)
                    . ' collected=' . json_encode($data['collected_amount'] ?? null),
                'would apply: ' . ($payloads ? implode(', ', array_column($payloads, 'event')) : 'nothing'),
            ]];
        }

        if ($payloads === []) {
            return $base + ['note' => 'Unknown transfer_status: ' . ($data['transfer_status'] ?? '—')];
        }

        $messages = [];
        foreach ($payloads as $payload) {
            $result = $this->webhooks->processTrusted($payload, OrderStatusHistory::SOURCE_CATCH_UP);
            $messages[] = $payload['event'] . ': ' . ($result['data']['status'] ?? $result['message']);
            if (str_contains((string) ($result['data']['status'] ?? ''), 'Order status updated')) {
                $base['updated'] = true;
            }
        }

        return $base + ['events' => $messages];
    }

    /**
     * Turn CarryBee's order details into the webhook payload(s) it implies.
     * `transfer_status` is human text ("In transit", "Delivered"); it is matched
     * to the webhook event of the same name. An invoice with a paid payment
     * status also implies order.paid.
     */
    public function payloadsFromDetails(array $data, DeliveryAssignedInfo $assigned): array
    {
        $common = [
            'store_id' => $data['store_id'] ?? null,
            'consignment_id' => $data['consignment_id'] ?? $assigned->consignment_id,
            'merchant_order_id' => $data['merchant_order_id'] ?? $assigned->merchant_order_id,
            'timestamptz' => $data['updated_at'] ?? now()->toIso8601String(),
            'source' => 'catch-up',
        ];

        $payloads = [];
        $event = $this->eventFromTransferStatus((string) ($data['transfer_status'] ?? ''));
        if ($event !== null) {
            $payloads[] = array_filter($common + [
                'event' => $event,
                'collected_amount' => $data['collected_amount'] ?? null,
                'collectable_amount' => $data['collectable_amount'] ?? null,
                'cod_fee' => $data['cod_fee'] ?? null,
                'delivery_fee' => $data['delivery_fee'] ?? null,
                'attempt' => $data['attempt'] ?? null,
                'reason' => $data['reason'] ?? null,
            ], fn ($v) => $v !== null);
        }

        // Paid only when CarryBee says exactly "paid" AND gives an invoice. A loose
        // "contains paid" check matched values like "Not Paid" and settled unpaid orders.
        $invoiceId = trim((string) ($data['invoice_id'] ?? ''));
        $paymentStatus = Str::lower(trim((string) ($data['payment_status'] ?? '')));
        if ($event !== 'order.paid' && $invoiceId !== '' && in_array($paymentStatus, self::PAID_PAYMENT_STATUSES, true)) {
            $payloads[] = $common + ['event' => 'order.paid', 'invoice_id' => $invoiceId];
        }

        return $payloads;
    }

    public function eventFromTransferStatus(string $transferStatus): ?string
    {
        $slug = Str::of($transferStatus)->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->toString();
        if ($slug === '') {
            return null;
        }

        $aliases = [
            'partially-delivered' => 'partial-delivery',
            'on-hold' => 'delivery-on-hold',
            'hold' => 'delivery-on-hold',
            'returned-to-store' => 'returned-to-merchant',
            'return-to-merchant' => 'returned-to-merchant',
            'pickup-pending' => 'pickup-requested',
            'out-for-delivery' => 'assigned-for-delivery',
        ];
        $slug = $aliases[$slug] ?? $slug;

        $event = 'order.' . $slug;
        if (array_key_exists($event, (array) config('carrybee.event_status_map', []))) {
            return $event;
        }

        Log::info('CarryBee catch-up: unmapped transfer_status', ['transfer_status' => $transferStatus]);

        return null;
    }
}
