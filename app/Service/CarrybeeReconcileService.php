<?php

namespace App\Service;

use App\Models\CarrybeeInvoiceLine;
use App\Models\DeliveryAssignedInfo;
use App\Models\Order;
use App\Models\OrderReturnPenalty;
use App\Models\OrderSettlement;
use App\Models\PayoutFlag;
use App\Models\SettlementInvoice;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Makes our payouts match CarryBee's FINAL invoices, order by order.
 *
 * 1. syncAccount(): read every CarryBee invoice of an account with its parcel lines and store them
 *    (carrybee_invoice_lines), matched to our orders by consignment id (return lines "R…" by order number).
 * 2. reconcileOrder(): copy CarryBee's collected cash and fees onto the order, then
 *    - settlements that are still unpaid are rewritten from the breakdown (which now reads CarryBee's lines);
 *    - payouts already made, or locked on a pending payout invoice, are never changed: a payout_flags row
 *      (overpaid / underpaid / overcharged / undercharged) records the difference instead;
 *    - returned parcels get an order_return_penalties row (CarryBee charges − cash) whose payer the admin picks.
 * Dropshipper profit never goes below 0 (calculateOrderStakeBreakdown floors it).
 */
class CarrybeeReconcileService
{
    private const DETAIL_CACHE_HOURS = 24;

    public function __construct(
        private readonly CarrybeeInvoiceReportService $invoices,
        private readonly OrderAccountingService $accounting,
    ) {
    }

    /* ------------------------------------------------------------------ sync */

    public function account(string $key): array
    {
        $account = collect($this->invoices->accounts())->firstWhere('key', $key);
        if (!$account) {
            throw new InvalidArgumentException("Unknown CarryBee account '{$key}'.");
        }

        return $account;
    }

    /** CarryBee account that shipped this order ('platform' or 'vendor-{id}'). */
    public function accountKeyForOrder(Order $order): string
    {
        $order->loadMissing(['items.product.vendor', 'vendor']);
        $vendor = $order->items->first()?->product?->vendor ?: $order->vendor;

        return $vendor?->has_own_delivery_account ? "vendor-{$vendor->id}" : 'platform';
    }

    /**
     * Pull every invoice of an account, store its lines and reconcile the matched orders.
     * Paid invoices already stored are not fetched again unless $refresh.
     */
    public function syncAccount(string $key, bool $refresh = false): array
    {
        $account = $this->account($key);
        // A vendor's main key syncs all its CarryBee accounts: the current one and any old ones
        // (a vendor that moved accounts keeps its history on the old account).
        $accounts = $account['type'] === 'vendor' && empty($account['old'])
            ? $this->invoices->vendorAccounts((int) $account['vendor_id'])
            : [$account];

        $invoiceCount = 0;
        $lines = 0;
        $errors = [];
        foreach ($accounts as $acc) {
            $list = $this->invoices->invoicesFor($acc, $refresh);
            if ($list['error']) {
                $errors[] = (count($accounts) > 1 ? "{$acc['label']}: " : '') . $list['error'];
                continue;
            }
            $invoiceCount += count($list['invoices']);
            foreach ($list['invoices'] as $row) {
                $invoiceId = (string) ($row['invoice_id'] ?? '');
                if ($invoiceId === '') {
                    continue;
                }
                $stored = CarrybeeInvoiceLine::where('invoice_id', $invoiceId)->exists();
                if ($stored && !$refresh && strtolower((string) ($row['receivable_status'] ?? '')) === 'paid') {
                    continue;
                }
                try {
                    $lines += $this->syncInvoice($acc, $invoiceId, $refresh);
                } catch (\Throwable $e) {
                    $errors[] = "{$invoiceId}: {$e->getMessage()}";
                }
            }
        }

        $keys = array_column($accounts, 'key');

        // CarryBee paid these parcels but we never got its "paid" message (e.g. order #199, Nov 2026):
        // replay it so the order goes through the normal paid journey before reconciling.
        $recovered = [];
        CarrybeeInvoiceLine::whereIn('account_key', $keys)->whereNotNull('order_id')
            ->where('line_type', CarrybeeInvoiceLine::TYPE_DELIVERY)->get()
            ->each(function (CarrybeeInvoiceLine $line) use (&$recovered) {
                if ($number = $this->recoverMissedPayment($line)) {
                    $recovered[] = $number;
                }
            });

        $orderIds = CarrybeeInvoiceLine::whereIn('account_key', $keys)->whereNotNull('order_id')->distinct()->pluck('order_id');
        $results = Order::whereIn('id', $orderIds)->get()->map(fn (Order $o) => $this->reconcileOrder($o))->values()->all();

        return [
            'account' => $key,
            'accounts' => array_map(fn ($a) => ['key' => $a['key'], 'label' => $a['label'], 'old' => !empty($a['old'])], $accounts),
            'error' => $errors ? implode('; ', $errors) : null,
            'invoices' => $invoiceCount,
            'lines' => $lines,
            'recovered_paid' => $recovered,
            'orders' => $results,
        ];
    }

    /** Statuses where CarryBee may still owe us a "paid" message: Delivered, Partially Delivered. */
    private const AWAITING_PAYMENT_STATUSES = [6, 13];

    /**
     * A CarryBee invoice line shows a delivered parcel that CarryBee already paid out, but the order
     * never recorded that payment (the "paid" webhook was missed). Replays CarryBee's order.paid event
     * through the trusted webhook handler, so status, settlements and deposits follow the normal path.
     * Returns the order number when it recovered one.
     */
    public function recoverMissedPayment(CarrybeeInvoiceLine $line): ?string
    {
        if (!in_array(strtolower((string) $line->receivable_status), ['paid', 'received'], true)
            || !str_contains(strtolower((string) $line->delivery_status), 'deliver')) {
            return null;
        }
        $order = Order::find($line->order_id);
        if (!$order || $order->carrybee_paid_at || !in_array((int) $order->status, self::AWAITING_PAYMENT_STATUSES, true)) {
            return null;
        }

        $result = app(CarrybeeWebhookService::class)->processTrusted([
            'event' => 'order.paid',
            'consignment_id' => $line->consignment_id,
            'merchant_order_id' => $line->merchant_order_id ?: $order->order_number,
            'invoice_id' => $line->invoice_id,
            'collected_amount' => (float) $line->collected,
            'delivery_fee' => (float) $line->delivery_fee,
            'cod_fee' => (float) $line->cod_fee,
            'timestamptz' => optional($line->invoiced_date)->format('Y-m-d') . 'T00:00:00+06:00',
            'source' => 'carrybee-invoice-sync',
        ], \App\Models\OrderStatusHistory::SOURCE_SYSTEM);

        return ($result['ok'] ?? false) && $order->fresh()->carrybee_paid_at ? $order->order_number : null;
    }

    /** Fetch one invoice's parcel lines from CarryBee and store them. Returns the number of lines. */
    public function syncInvoice(array $account, string $invoiceId, bool $refresh = false): int
    {
        $service = $this->invoices->serviceFor($account);
        if (!$service) {
            throw new InvalidArgumentException('CarryBee credentials are missing for this account.');
        }

        $cacheKey = 'carrybee-invoice-detail:' . $account['key'] . ':' . $invoiceId;
        if ($refresh) {
            Cache::forget($cacheKey);
        }
        $invoice = Cache::get($cacheKey);
        if (!$invoice) {
            $result = $service->getInvoiceDetails($invoiceId);
            $body = is_array($result['body']) ? $result['body'] : [];
            if ($result['status'] >= 400 || !empty($body['error'])) {
                throw new InvalidArgumentException('CarryBee said: ' . ($body['message'] ?? $result['raw'] ?? 'request failed') . " (HTTP {$result['status']})");
            }
            $invoice = $body['data'] ?? [];
            Cache::put($cacheKey, $invoice, now()->addHours(self::DETAIL_CACHE_HOURS));
        }

        $count = 0;
        foreach (($invoice['consignments'] ?? []) as $c) {
            $consignment = trim((string) ($c['consignment_id'] ?? ''));
            if ($consignment === '') {
                continue;
            }
            $isReturn = str_starts_with(strtoupper($consignment), 'R') || ($c['delivery_status'] ?? '') === 'returned_to_merchant';
            $merchantOrderId = $c['merchant_order_id'] ?? null;
            CarrybeeInvoiceLine::updateOrCreate(
                ['invoice_id' => $invoiceId, 'consignment_id' => $consignment],
                [
                    'account_key' => $account['key'],
                    'vendor_id' => $account['vendor_id'],
                    'invoiced_date' => $invoice['invoiced_date'] ?? $invoice['invoice_date'] ?? null,
                    'receivable_status' => $invoice['receivable_status'] ?? null,
                    'merchant_order_id' => $merchantOrderId,
                    'order_id' => $this->matchOrderId($consignment, $merchantOrderId, $isReturn),
                    'line_type' => $isReturn ? CarrybeeInvoiceLine::TYPE_RETURN : CarrybeeInvoiceLine::TYPE_DELIVERY,
                    'collected' => round((float) ($c['collected_amount'] ?? 0), 2),
                    'delivery_fee' => round((float) ($c['delivery_fee'] ?? 0), 2),
                    'cod_fee' => round((float) ($c['cod_fee'] ?? 0), 2),
                    'total_fee' => round((float) ($c['total_fee'] ?? ((float) ($c['delivery_fee'] ?? 0) + (float) ($c['cod_fee'] ?? 0))), 2),
                    'delivery_status' => $c['delivery_status'] ?? null,
                ]
            );
            $count++;
        }

        return $count;
    }

    /** CarryBee "paid" webhook: store that invoice and reconcile its orders. */
    public function syncPaidInvoice(Order $order, string $invoiceId): void
    {
        if ($invoiceId === '' || str_starts_with(strtoupper($invoiceId), 'MANUAL-')) {
            return;
        }
        $account = $this->account($this->accountKeyForOrder($order));
        $this->syncInvoice($account, $invoiceId);
        Order::whereIn('id', CarrybeeInvoiceLine::where('invoice_id', $invoiceId)->whereNotNull('order_id')->pluck('order_id'))
            ->get()->each(fn (Order $o) => $this->reconcileOrder($o));
    }

    private function matchOrderId(string $consignment, ?string $merchantOrderId, bool $isReturn): ?int
    {
        if (!$isReturn) {
            $id = DeliveryAssignedInfo::where('consignment_id', $consignment)->value('order_id');
            if ($id) {
                return (int) $id;
            }
        }

        return $merchantOrderId ? (Order::where('order_number', $merchantOrderId)->value('id') ?: null) : null;
    }

    /* ------------------------------------------------------------- reconcile */

    public function reconcileOrder(Order $order): array
    {
        return DB::transaction(function () use ($order) {
            $order = Order::lockForUpdate()->with(['deliveryInformation', 'orderStatus'])->find($order->id);
            $lines = CarrybeeInvoiceLine::where('order_id', $order->id)->get();
            $summary = ['order_id' => $order->id, 'order_number' => $order->order_number, 'status' => 'not_invoiced', 'changes' => [], 'flags' => []];
            if ($lines->isEmpty()) {
                return $summary;
            }

            $returned = $this->accounting->isCancelledOrReturned($order);
            $delivery = $lines->where('line_type', CarrybeeInvoiceLine::TYPE_DELIVERY)->sortByDesc('invoiced_date')->first();

            if ($delivery && !$returned) {
                $col = round((float) $delivery->collected, 2);
                $del = round((float) $delivery->delivery_fee, 2);
                $cod = round((float) $delivery->cod_fee, 2);
                $updates = [
                    'remaining_cod' => $col,
                    'actual_courier_fee' => $del,
                    'actual_cod_fee' => $cod,
                    'actual_courier_cost' => round($del + $cod, 2),
                    'courier_cost_confirmed' => true,
                    'courier_net_payout' => max(0.0, round($col - $del - $cod, 2)),
                ];
                foreach ($updates as $field => $value) {
                    if (is_float($value) && abs((float) $order->{$field} - $value) >= 0.01 || $order->{$field} === null) {
                        $summary['changes'][$field] = ['from' => $order->{$field}, 'to' => $value];
                    }
                }
                $order->fill($updates);
                $order->deliveryInformation?->update([
                    'delivery_fee' => $del,
                    'cod_fee' => $cod,
                    'total_fee' => round($del + $cod, 2),
                    'collected_amount' => $col,
                ]);
            }

            if ($returned) {
                $amount = round(max(0.0, $lines->sum('total_fee') - $lines->sum('collected')), 2);
                if ($amount > 0) {
                    $penalty = OrderReturnPenalty::firstOrNew(['order_id' => $order->id]);
                    if (abs((float) $penalty->amount - $amount) >= 0.01) {
                        $summary['changes']['return_penalty'] = ['from' => $penalty->amount, 'to' => $amount];
                    }
                    $penalty->amount = $amount;
                    $penalty->save();
                }
            }

            $order->carrybee_reconciled_at = now();
            $order->save();

            $b = $this->accounting->calculateOrderStakeBreakdown($order->fresh(['items.shop', 'items.product.vendor', 'deliveryInformation', 'orderStatus', 'user']));
            $settlements = OrderSettlement::where('order_id', $order->id)->get()->keyBy('settlement_type');

            // Vendor (own CarryBee account): what the vendor owes the platform.
            $supplier = $settlements->get(OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE);
            if ($supplier && $b['has_own_delivery_account']) {
                $correct = round((float) $b['supplier']['supplier_owes_platform'], 2);
                if ($supplier->status !== OrderSettlement::STATUS_SETTLED) {
                    if (abs((float) $supplier->settleable_amount - $correct) >= 0.01) {
                        $summary['changes']['vendor_owes'] = ['from' => (float) $supplier->settleable_amount, 'to' => $correct];
                    }
                    $supplier->update([
                        'gross_entitlement' => $b['supplier']['vendor_price'],
                        'deducted_by_courier' => $b['courier']['net_received_by_supplier'],
                        'settleable_amount' => $correct,
                        'net_payable_amount' => $correct,
                        'direction' => $correct >= 0 ? OrderSettlement::DIRECTION_PAYEE_OWES_PLATFORM : OrderSettlement::DIRECTION_PLATFORM_OWES_PAYEE,
                    ]);
                    $this->clearFlag($order->id, 'vendor');
                } else {
                    $flag = $this->flag($order->id, 'vendor', $supplier, (float) $supplier->settleable_amount, $correct,
                        ['overcharged', 'undercharged'], 'Vendor invoice already paid with figures that differ from CarryBee');
                    if ($flag) {
                        $summary['flags'][] = $flag;
                    }
                }
            }

            // Dropshipper profit (never below 0).
            $reseller = $settlements->get(OrderSettlement::TYPE_RESELLER_PROFIT);
            if ($reseller && !$returned) {
                $correct = round(max(0.0, (float) $b['dropshipper']['profit']), 2);
                $lockedOnPayout = $this->onPendingPayout($order);
                if ($reseller->status !== OrderSettlement::STATUS_SETTLED && !$lockedOnPayout) {
                    if (abs((float) $reseller->settleable_amount - $correct) >= 0.01) {
                        $summary['changes']['dropshipper_profit'] = ['from' => (float) $reseller->settleable_amount, 'to' => $correct];
                    }
                    $reseller->update(['gross_entitlement' => $correct, 'settleable_amount' => $correct, 'net_payable_amount' => $correct]);
                    $order->update(['reseller_profit' => $correct]);
                    $this->accounting->allocateResellerProfitToItems($order, $correct);
                    $this->clearFlag($order->id, 'dropshipper');
                } else {
                    $flag = $this->flag($order->id, 'dropshipper', $reseller, (float) $reseller->settleable_amount, $correct, ['overpaid', 'underpaid'],
                        $lockedOnPayout ? 'On a pending payout invoice with a different amount' : 'Already paid with an amount that differs from CarryBee');
                    if ($flag) {
                        $summary['flags'][] = $flag;
                    }
                }
            }

            // Platform margins (internal rows) follow CarryBee too.
            if (!$returned) {
                $settlements->get(OrderSettlement::TYPE_COMPANY_LOGISTIC_EARNING)?->update([
                    'gross_entitlement' => $b['platform']['delivery_margin'],
                    'settleable_amount' => $b['platform']['delivery_margin'],
                    'net_payable_amount' => $b['platform']['delivery_margin'],
                    'admin_note' => "Platform shipping margin from CarryBee invoice: ৳{$b['customer_shipping_fee']} customer charge - ৳{$b['courier']['delivery_fee']} CarryBee delivery fee",
                ]);
                $settlements->get(OrderSettlement::TYPE_COMPANY_PRODUCT_COMMISSION)?->update([
                    'gross_entitlement' => $b['platform']['product_commission'],
                    'settleable_amount' => $b['platform']['product_commission'],
                    'net_payable_amount' => $b['platform']['product_commission'],
                ]);
                Transaction::where('order_id', $order->id)->where('type', 'platform_order_margin')
                    ->update(['amount' => round((float) $b['platform']['net_profit'], 2)]);
            }

            $summary['status'] = 'reconciled';

            return $summary;
        });
    }

    /** Admin decides who carries a returned parcel's CarryBee charges. */
    public function setPenaltyPayer(Order $order, ?string $payer, ?string $note, ?int $adminId): OrderReturnPenalty
    {
        if ($payer !== null && !in_array($payer, OrderReturnPenalty::PAYERS, true)) {
            throw new InvalidArgumentException('Payer must be vendor, admin or dropshipper.');
        }
        $penalty = OrderReturnPenalty::where('order_id', $order->id)->first();
        if (!$penalty) {
            throw new InvalidArgumentException('This order has no CarryBee return charge. Sync with CarryBee first.');
        }
        $penalty->update([
            'payer' => $payer,
            'note' => $note,
            'decided_by' => $payer ? $adminId : null,
            'decided_at' => $payer ? now() : null,
        ]);
        $this->reconcileOrder($order);

        return $penalty->fresh();
    }

    public function resolveFlag(PayoutFlag $flag, ?string $note, ?int $adminId): PayoutFlag
    {
        $flag->update(['status' => PayoutFlag::STATUS_RESOLVED, 'note' => $note, 'resolved_by' => $adminId, 'resolved_at' => now()]);

        return $flag->fresh();
    }

    private function flag(int $orderId, string $party, OrderSettlement $settlement, float $recorded, float $correct, array $names, string $reason): ?array
    {
        $diff = round($recorded - $correct, 2);
        if (abs($diff) < 0.01) {
            $this->clearFlag($orderId, $party);

            return null;
        }
        $existing = PayoutFlag::where('order_id', $orderId)->where('party', $party)->first();
        $flag = PayoutFlag::updateOrCreate(['order_id' => $orderId, 'party' => $party], [
            'settlement_id' => $settlement->id,
            'flag' => $diff > 0 ? $names[0] : $names[1],
            'recorded' => round($recorded, 2),
            'correct' => $correct,
            'difference' => $diff,
            'reason' => $reason,
            // A resolved flag stays resolved unless the amounts changed since.
            'status' => $existing && $existing->status === PayoutFlag::STATUS_RESOLVED && abs((float) $existing->difference - $diff) < 0.01
                ? PayoutFlag::STATUS_RESOLVED : PayoutFlag::STATUS_OPEN,
        ]);

        return $flag->only(['party', 'flag', 'recorded', 'correct', 'difference', 'status']);
    }

    private function clearFlag(int $orderId, string $party): void
    {
        PayoutFlag::where('order_id', $orderId)->where('party', $party)->where('status', PayoutFlag::STATUS_OPEN)
            ->update(['status' => PayoutFlag::STATUS_RESOLVED, 'note' => 'Matches CarryBee now', 'resolved_at' => now()]);
    }

    private function onPendingPayout(Order $order): bool
    {
        return SettlementInvoice::where('type', SettlementInvoice::TYPE_DROPSHIPPER_PAYOUT)
            ->where('status', SettlementInvoice::STATUS_PENDING)
            ->where('dropshipper_id', $order->user_id)
            ->get(['order_ids'])
            ->contains(fn ($inv) => in_array($order->id, array_map('intval', (array) $inv->order_ids), true));
    }

    /* ---------------------------------------------------------------- report */

    /** Order-by-order view of one account against CarryBee, with penalties, flags and the vendor's real balance. */
    public function report(string $key): array
    {
        $account = $this->account($key);
        // A vendor's report covers all its CarryBee accounts (current + old).
        $accounts = $account['type'] === 'vendor' && empty($account['old'])
            ? $this->invoices->vendorAccounts((int) $account['vendor_id'])
            : [$account];
        $lines = CarrybeeInvoiceLine::whereIn('account_key', array_column($accounts, 'key'))->get();
        $orderIds = $lines->pluck('order_id')->filter()->unique()->values();
        $orders = Order::with(['orderStatus'])->whereIn('id', $orderIds)->orderByDesc('id')->get();
        $penalties = OrderReturnPenalty::whereIn('order_id', $orderIds)->get()->keyBy('order_id');
        $flags = PayoutFlag::whereIn('order_id', $orderIds)->get()->groupBy('order_id');
        $settlements = OrderSettlement::whereIn('order_id', $orderIds)->get()->groupBy('order_id');
        $vendorInvoices = SettlementInvoice::where('type', SettlementInvoice::TYPE_VENDOR_SETTLEMENT)
            ->whereNotIn('status', [SettlementInvoice::STATUS_REJECTED, SettlementInvoice::STATUS_CANCELLED])
            ->when($account['vendor_id'], fn ($q) => $q->where('vendor_id', $account['vendor_id']))
            ->latest()->get();

        $rows = $orders->map(function (Order $o) use ($lines, $penalties, $flags, $settlements, $vendorInvoices) {
            $b = $this->accounting->calculateOrderStakeBreakdown($o);
            $mine = $lines->where('order_id', $o->id);
            $set = ($settlements[$o->id] ?? collect())->keyBy('settlement_type');
            $sup = $set->get(OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE);
            $rs = $set->get(OrderSettlement::TYPE_RESELLER_PROFIT);
            $inv = $vendorInvoices->first(fn ($i) => in_array($o->id, array_map('intval', (array) $i->order_ids), true));
            $returned = $this->accounting->isCancelledOrReturned($o);

            return [
                'order_id' => $o->id,
                'order_number' => $o->order_number,
                'status' => $o->orderStatus?->name ?? (string) $o->status,
                'returned' => $returned,
                'reconciled_at' => $o->carrybee_reconciled_at?->toIso8601String(),
                'invoice_ids' => $mine->pluck('invoice_id')->unique()->values()->all(),
                'collected' => round($mine->sum('collected'), 2),
                'delivery_fee' => round($mine->where('line_type', 'delivery')->sum('delivery_fee'), 2),
                'cod_fee' => round($mine->sum('cod_fee'), 2),
                'total_fee' => round($mine->sum('total_fee'), 2),
                'carrybee_net' => round($mine->sum('collected') - $mine->sum('total_fee'), 2),
                'supplier_price' => $returned ? 0.0 : (float) $b['supplier']['vendor_price'],
                'vendor_owes' => (float) $b['supplier']['supplier_owes_platform'],
                'vendor_settlement' => $sup ? ['amount' => (float) $sup->settleable_amount, 'status' => $sup->status] : null,
                'dropshipper_profit' => (float) $b['dropshipper']['profit'],
                'dropshipper_settlement' => $rs ? ['amount' => (float) $rs->settleable_amount, 'status' => $rs->status] : null,
                'platform_profit' => (float) $b['platform']['net_profit'],
                'penalty' => ($p = $penalties[$o->id] ?? null) ? ['amount' => $p->amount, 'payer' => $p->payer, 'note' => $p->note] : null,
                'flags' => ($flags[$o->id] ?? collect())->map(fn ($f) => $f->only(['id', 'party', 'flag', 'recorded', 'correct', 'difference', 'reason', 'status', 'note']))->values()->all(),
                'vendor_invoice' => $inv ? ['id' => $inv->id, 'number' => $inv->invoice_number, 'status' => $inv->status] : null,
            ];
        })->values();

        $penaltyVendor = $rows->sum(fn ($r) => $r['penalty'] && $r['penalty']['payer'] === 'vendor' ? $r['penalty']['amount'] : 0);

        return [
            'account' => ['key' => $account['key'], 'label' => $account['label'], 'type' => $account['type'], 'vendor_id' => $account['vendor_id']],
            'accounts' => array_map(fn ($a) => ['key' => $a['key'], 'label' => $a['label'], 'old' => !empty($a['old'])], $accounts),
            'synced_at' => optional($lines->max('updated_at'))->toIso8601String(),
            'totals' => [
                'orders' => $rows->count(),
                'carrybee_net' => round($lines->sum('collected') - $lines->sum('total_fee'), 2),
                'supplier_price' => round($rows->sum('supplier_price'), 2),
                'vendor_owes' => round($rows->sum('vendor_owes'), 2),
                // For a vendor's own account: CarryBee paid − supplier price (+ penalties the vendor carries).
                'expected_vendor_owes' => $account['type'] === 'vendor'
                    ? round($lines->sum('collected') - $lines->sum('total_fee') - $rows->sum('supplier_price') + $penaltyVendor, 2) : null,
                'penalties' => round($rows->sum(fn ($r) => $r['penalty']['amount'] ?? 0), 2),
                'penalties_undecided' => $rows->filter(fn ($r) => $r['penalty'] && !$r['penalty']['payer'])->count(),
                'open_flags' => $rows->sum(fn ($r) => collect($r['flags'])->where('status', 'open')->count()),
            ],
            'orders' => $rows->all(),
            'unmatched_lines' => $lines->whereNull('order_id')->map(fn ($l) => $l->only(['invoice_id', 'consignment_id', 'merchant_order_id', 'collected', 'total_fee', 'delivery_status']))->values()->all(),
            'vendor_invoices' => $vendorInvoices->map(fn ($i) => [
                'id' => $i->id, 'number' => $i->invoice_number, 'status' => $i->status, 'orders' => count((array) $i->order_ids),
                'calculated' => (float) ($i->calculated_amount ?? $i->total_amount_owed), 'final' => (float) $i->total_amount_owed,
            ])->values()->all(),
        ];
    }

    /* --------------------------------------------------- corrected invoice */

    /**
     * Replace an unpaid vendor invoice with a corrected one built from CarryBee-matched figures.
     * The old one is rejected (kept for history); the new one has the same orders and no manual adjustment.
     */
    public function reissueVendorInvoice(int $invoiceId, ?int $adminId): array
    {
        $old = SettlementInvoice::where('type', SettlementInvoice::TYPE_VENDOR_SETTLEMENT)->findOrFail($invoiceId);
        if (!in_array($old->status, [SettlementInvoice::STATUS_PENDING, SettlementInvoice::STATUS_REJECTED], true)) {
            throw new InvalidArgumentException("Invoice {$old->invoice_number} is {$old->status}; only an unpaid invoice can be replaced.");
        }
        $orderIds = array_values(array_map('intval', (array) $old->order_ids));
        $orders = Order::with('deliveryInformation')->whereIn('id', $orderIds)->get();

        $missing = $orders->filter(fn (Order $o) => $o->consignment_id && !CarrybeeInvoiceLine::where('order_id', $o->id)->exists())
            ->pluck('order_number')->all();
        if ($missing) {
            throw new InvalidArgumentException('Not on any CarryBee invoice yet (sync first): ' . implode(', ', $missing));
        }
        $undecided = OrderReturnPenalty::whereIn('order_id', $orderIds)->whereNull('payer')->where('amount', '>', 0)->pluck('order_id');
        if ($undecided->isNotEmpty()) {
            $numbers = $orders->whereIn('id', $undecided)->pluck('order_number')->implode(', ');
            throw new InvalidArgumentException("Decide who pays the CarryBee return charge first: {$numbers}");
        }

        return DB::transaction(function () use ($old, $orderIds, $orders, $adminId) {
            $orders->each(fn (Order $o) => $this->reconcileOrder($o));

            $new = $this->accounting->createVendorSettlementInvoice([
                'vendor_id' => $old->vendor_id,
                'order_ids' => $orderIds,
                'payment_method' => $old->payment_method,
                'note' => "Corrected from {$old->invoice_number} using CarryBee final invoices",
            ], $adminId);

            if ($old->status === SettlementInvoice::STATUS_PENDING) {
                $this->accounting->rejectVendorInvoice($old->id, "Replaced by {$new->invoice_number} (matched to CarryBee final invoices)", $adminId);
            }

            // CarryBee check: cash CarryBee paid for these orders − supplier price (+ penalties the vendor carries).
            $lines = CarrybeeInvoiceLine::whereIn('order_id', $orderIds)->get();
            $breakdowns = collect($new->orders_breakdown);
            $supplierPrice = $breakdowns->sum(fn ($b) => (float) $b['supplier']['vendor_price']);
            $vendorPenalties = OrderReturnPenalty::whereIn('order_id', $orderIds)->where('payer', 'vendor')->sum('amount');
            $expected = round($lines->sum('collected') - $lines->sum('total_fee') - $supplierPrice + $vendorPenalties, 2);

            return [
                'old' => ['id' => $old->id, 'number' => $old->invoice_number, 'final' => (float) $old->total_amount_owed],
                'new' => $new->fresh(),
                'carrybee_check' => [
                    'carrybee_net' => round($lines->sum('collected') - $lines->sum('total_fee'), 2),
                    'supplier_price' => round($supplierPrice, 2),
                    'expected' => $expected,
                    'invoice' => round((float) $new->total_amount_owed, 2),
                    'matches' => abs($expected - (float) $new->total_amount_owed) < 0.05,
                ],
            ];
        });
    }

    /** All accounts, for the scheduled command. */
    public function syncAll(): Collection
    {
        // Old accounts are synced together with their vendor's main account.
        return collect($this->invoices->accounts())->filter(fn ($a) => empty($a['old']))->map(fn ($a) => $this->syncAccount($a['key']))->values();
    }
}
