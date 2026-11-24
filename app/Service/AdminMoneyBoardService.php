<?php

namespace App\Service;

use App\Models\CarrybeeInvoiceLine;
use App\Models\Order;
use App\Models\OrderReturnPenalty;
use App\Models\OrderSettlement;
use App\Models\PayoutFlag;
use App\Models\SettlementInvoice;
use Illuminate\Support\Facades\Cache;

/**
 * Admin dashboard "Money board": who owes whom, and the admin's final income.
 *
 * Every order goes through three money stages (OrderPaymentProgressService):
 *  1. Waiting for CarryBee — delivered, CarryBee hasn't paid: the cash is with CarryBee, everyone waits.
 *  2. Settling — CarryBee paid (into the platform account or the vendor's own account); the vendor,
 *     the admin and the dropshipper still owe each other until the vendor invoice and the
 *     dropshipper payout are settled.
 *  3. Completed — both settled: the admin's income on that order is final.
 *
 * Admin income per order = CarryBee cash − CarryBee fees − supplier price − dropshipper profit
 * (the same as product markup + delivery margin − anything the platform absorbs).
 * Final income uses what was actually settled, so an overpaid dropshipper lowers it.
 * Figures come from calculateOrderStakeBreakdown(), which reads CarryBee's final invoice lines once synced.
 */
class AdminMoneyBoardService
{
    private const DELIVERED = [6, 10, 13];
    private const COMPLETED = 9;
    private const RETURNED = 8;

    public function __construct(
        private readonly OrderAccountingService $accounting,
        private readonly OrderPaymentProgressService $progress,
    ) {
    }

    /** Cached for 2 minutes (it reads every delivered order); $fresh rebuilds now. */
    public function cached(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('admin-money-board');
        }

        return Cache::remember('admin-money-board', now()->addMinutes(2), fn () => $this->build());
    }

    public function build(): array
    {
        $orders = Order::with(['settlements', 'deliveryInformation', 'items.shop', 'items.product.vendor', 'orderStatus', 'user'])
            ->whereIn('status', array_merge(self::DELIVERED, [self::COMPLETED]))
            ->get();
        $reconciled = CarrybeeInvoiceLine::whereNotNull('order_id')->distinct()->pluck('order_id')->flip();
        $this->invoicedVendorAmounts = $this->paidVendorInvoiceAmounts();

        $waiting = $this->bucket();
        $settling = $this->bucket();
        $completed = $this->bucket();
        $vendorsOwe = [];      // vendor => amount the vendor owes the admin
        $adminOwesVendors = [];
        $adminOwesDrop = [];
        $absorbed = 0.0;
        $notSynced = 0;
        $monthStart = now()->startOfMonth();
        $incomeThisMonth = 0.0;

        foreach ($orders as $order) {
            $b = $this->accounting->calculateOrderStakeBreakdown($order);
            $stage = $this->progress->build($order)['stage'];
            $row = $this->money($order, $b);

            if ($stage === 'carrybee_pending') {
                $this->add($waiting, $row, false);
                continue;
            }
            if ($order->is_carrybee_paid && !$reconciled->has($order->id)) {
                $notSynced++;
            }
            $absorbed += $row['absorbed'];

            if ($stage === 'completed') {
                $this->add($completed, $row, true);
                $settledAt = $order->settlements->max('settled_at');
                if ($settledAt && $settledAt >= $monthStart) {
                    $incomeThisMonth += $row['income_final'];
                }
                continue;
            }

            // Settling: CarryBee paid; vendor and/or dropshipper not settled.
            $this->add($settling, $row, false);
            if (!$row['vendor_settled']) {
                $vendorKey = $row['vendor_id'] ?: 0;
                if ($row['own_account'] && $row['vendor_owes'] > 0) {
                    $this->party($vendorsOwe, $vendorKey, $row['vendor_name'], $row['vendor_owes']);
                } elseif ($row['own_account'] && $row['vendor_owes'] < 0) {
                    $this->party($adminOwesVendors, $vendorKey, $row['vendor_name'], -$row['vendor_owes']);
                } elseif (!$row['own_account']) {
                    $this->party($adminOwesVendors, $vendorKey, $row['vendor_name'], $row['supplier_price']);
                }
            }
            if (!$row['dropshipper_settled'] && $row['dropshipper_profit'] > 0) {
                $this->party($adminOwesDrop, $order->user_id ?: 0, $order->user?->name ?: 'Dropshipper', $row['dropshipper_profit']);
            }
        }

        // Returned parcels: CarryBee's return charge and who carries it.
        $penalties = OrderReturnPenalty::with(['order.user', 'order.items.product.vendor', 'order.vendor'])->where('amount', '>', 0)->get();
        $dropOweAdmin = [];
        foreach ($penalties->where('payer', 'dropshipper') as $p) {
            $this->party($dropOweAdmin, $p->order?->user_id ?: 0, $p->order?->user?->name ?: 'Dropshipper', (float) $p->amount);
        }
        $penaltyAdmin = round((float) $penalties->where('payer', 'admin')->sum('amount'), 2);
        foreach ($penalties->whereNotNull('payer') as $p) {
            $o = $p->order;
            $vendor = $o?->items->first()?->product?->vendor ?: $o?->vendor;
            $sup = $o ? OrderSettlement::where('order_id', $o->id)->where('settlement_type', OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE)->first() : null;
            if (!$vendor || $sup?->status === OrderSettlement::STATUS_SETTLED) {
                continue;
            }
            if ($vendor->has_own_delivery_account && $p->payer !== 'vendor') {
                // CarryBee charged the vendor's own account; someone else carries it → admin credits the vendor.
                $this->party($adminOwesVendors, $vendor->id, $vendor->shop_name, (float) $p->amount);
            } elseif (!$vendor->has_own_delivery_account && $p->payer === 'vendor') {
                // CarryBee charged the platform account; the vendor carries it → vendor owes the admin.
                $this->party($vendorsOwe, $vendor->id, $vendor->shop_name, (float) $p->amount);
            }
        }

        $flags = PayoutFlag::where('status', PayoutFlag::STATUS_OPEN)->get();
        $owedToAdmin = $this->total($vendorsOwe) + $this->total($dropOweAdmin);
        $adminOwes = $this->total($adminOwesVendors) + $this->total($adminOwesDrop);

        return [
            'generated_at' => now()->toIso8601String(),
            'waiting_carrybee' => $this->finish($waiting),
            'settling' => $this->finish($settling),
            'completed' => $this->finish($completed) + ['income_this_month' => round($incomeThisMonth, 2)],
            'owes' => [
                'vendors_owe_admin' => $this->list($vendorsOwe),
                'admin_owes_vendors' => $this->list($adminOwesVendors),
                'admin_owes_dropshippers' => $this->list($adminOwesDrop),
                'dropshippers_owe_admin' => $this->list($dropOweAdmin),
                'owed_to_admin' => round($owedToAdmin, 2),
                'admin_owes' => round($adminOwes, 2),
                'net' => round($owedToAdmin - $adminOwes, 2),
            ],
            'alerts' => [
                'overpaid_open' => round((float) $flags->whereIn('flag', ['overpaid', 'undercharged'])->sum(fn ($f) => abs($f->difference)), 2),
                'underpaid_open' => round((float) $flags->whereIn('flag', ['underpaid', 'overcharged'])->sum(fn ($f) => abs($f->difference)), 2),
                'open_flags' => $flags->count(),
                'penalties_undecided' => $penalties->whereNull('payer')->count(),
                'penalties_undecided_amount' => round((float) $penalties->whereNull('payer')->sum('amount'), 2),
                'penalties_admin_paid' => $penaltyAdmin,
                'absorbed_by_platform' => round($absorbed, 2),
                'paid_not_synced' => $notSynced,
            ],
        ];
    }

    /** One order's money, from the CarryBee-aware breakdown and the recorded settlements. */
    private function money(Order $order, array $b): array
    {
        $collected = (float) $b['courier']['collectable_amount'];
        $fees = (float) $b['courier']['total_courier_fee'];
        $supplierPrice = (float) $b['supplier']['vendor_price'];
        $own = (bool) $b['has_own_delivery_account'];
        $rawProfit = round($collected - (float) $b['admin_base_price'] - (float) $b['customer_shipping_fee'] - (float) $b['courier']['cod_fee'], 2);
        $profit = max(0.0, $rawProfit);

        $sup = $order->settlements->firstWhere('settlement_type', OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE);
        $rs = $order->settlements->firstWhere('settlement_type', OrderSettlement::TYPE_RESELLER_PROFIT);
        $vendorSettled = $sup?->status === OrderSettlement::STATUS_SETTLED;
        $dropSettled = $rs?->status === OrderSettlement::STATUS_SETTLED;
        $vendorOwes = round((float) $b['supplier']['supplier_owes_platform'], 2);

        // Expected: correct figures. Final: what was actually settled where it was settled.
        $dropOut = $dropSettled ? (float) $rs->settleable_amount : $profit;
        if ($own) {
            // What the vendor really paid for this order is on its paid/approved vendor invoice. The
            // order's settlement row can be wrong (order #83: invoice paid ৳748.70, row says ৳0).
            $vendorIn = $vendorSettled
                ? ($this->invoicedVendorAmounts[$order->id] ?? (float) $sup->settleable_amount)
                : $vendorOwes;
            $expected = $vendorOwes - $profit;
            $final = $vendorIn - $dropOut;
        } else {
            $expected = $collected - $fees - $supplierPrice - $profit;
            $final = $collected - $fees - $supplierPrice - $dropOut;
        }

        return [
            'collected' => $collected,
            'fees' => $fees,
            'supplier_price' => $supplierPrice,
            'dropshipper_profit' => $profit,
            'own_account' => $own,
            'vendor_id' => $b['vendor_id'],
            'vendor_name' => $b['vendor_name'] ?: 'Vendor',
            'vendor_owes' => $vendorOwes,
            'vendor_settled' => $vendorSettled,
            'dropshipper_settled' => $dropSettled,
            'income_expected' => round($expected, 2),
            'income_final' => round($final, 2),
            'absorbed' => $rawProfit < 0 ? -$rawProfit : 0.0,
        ];
    }

    /** order_id => amount the vendor gave admin for it, from paid/approved vendor invoices' per-order snapshot. */
    private array $invoicedVendorAmounts = [];

    private function paidVendorInvoiceAmounts(): array
    {
        $map = [];
        SettlementInvoice::where('type', SettlementInvoice::TYPE_VENDOR_SETTLEMENT)
            ->whereIn('status', [SettlementInvoice::STATUS_PAID, SettlementInvoice::STATUS_APPROVED])
            ->orderBy('id')
            ->get(['id', 'orders_breakdown'])
            ->each(function (SettlementInvoice $inv) use (&$map) {
                foreach ((array) $inv->orders_breakdown as $b) {
                    if (isset($b['order_id'], $b['supplier']['supplier_owes_platform'])) {
                        $map[(int) $b['order_id']] = round((float) $b['supplier']['supplier_owes_platform'], 2);
                    }
                }
            });

        return $map;
    }

    private function bucket(): array
    {
        return ['orders' => 0, 'collected' => 0.0, 'courier_fees' => 0.0, 'supplier_share' => 0.0, 'dropshipper_share' => 0.0, 'admin_income' => 0.0,
            'platform_account' => 0.0, 'vendor_accounts' => 0.0];
    }

    private function add(array &$bucket, array $row, bool $final): void
    {
        $bucket['orders']++;
        $bucket['collected'] += $row['collected'];
        $bucket['courier_fees'] += $row['fees'];
        $bucket['supplier_share'] += $row['supplier_price'];
        $bucket['dropshipper_share'] += $row['dropshipper_profit'];
        $bucket['admin_income'] += $final ? $row['income_final'] : $row['income_expected'];
        // Where the cash lands / landed: CarryBee pays the account that shipped the parcel.
        $bucket[$row['own_account'] ? 'vendor_accounts' : 'platform_account'] += $row['collected'] - $row['fees'];
    }

    private function finish(array $bucket): array
    {
        return array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $bucket);
    }

    private function party(array &$list, $id, ?string $name, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $list[$id] ??= ['id' => $id ?: null, 'name' => $name ?: '—', 'orders' => 0, 'amount' => 0.0];
        $list[$id]['orders']++;
        $list[$id]['amount'] += $amount;
    }

    private function list(array $list): array
    {
        return collect($list)->map(fn ($r) => ['amount' => round($r['amount'], 2)] + $r)->sortByDesc('amount')->values()->all();
    }

    private function total(array $list): float
    {
        return (float) collect($list)->sum('amount');
    }
}
