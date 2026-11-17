<?php

namespace App\Service;

use App\Models\Order;
use App\Models\OrderSettlement;
use Illuminate\Database\Eloquent\Builder;

/**
 * One order's money journey for the admin order list ("Payment" column):
 * CarryBee paid -> dropshipper profit paid -> supplier settled -> Completed.
 * Same rules as OrderAccountingService::checkAndMarkOrderCompleted() and
 * Order::is_carrybee_paid, so "Completed" means the same thing everywhere.
 */
class OrderPaymentProgressService
{
    public const CANCELLED_STATUSES = [7, 8, 11];   // Cancelled, Returned, Lost / Damaged
    public const RETURNING_STATUS = 12;              // Return in Transit
    public const COMPLETED_STATUS = 9;
    public const DELIVERED_STATUSES = [6, 10, 13];   // Delivered, Carrybee Paid, Partially Delivered

    public const STAGES = [
        'cancelled', 'returning', 'not_delivered', 'carrybee_pending',
        'both_pending', 'dropshipper_pending', 'supplier_pending', 'completed',
    ];

    /** Relations build() reads; eager-load them on list queries. */
    public const RELATIONS = ['settlements', 'deliveryInformation'];

    public function build(Order $order): array
    {
        $status = (int) $order->status;
        $info = $order->deliveryInformation;
        $settlements = $order->relationLoaded('settlements') ? $order->settlements : $order->settlements()->get();

        // 1. CarryBee
        $hasConsignment = !empty($info?->consignment_id);
        $carrybeePaid = $hasConsignment && $order->is_carrybee_paid;
        $paidAt = $order->carrybee_paid_at ?: $info?->carrybee_paid_at;
        $carrybee = [
            'applicable' => $hasConsignment,
            'paid' => $carrybeePaid,
            'invoice_id' => $carrybeePaid ? ($order->carrybee_invoice_id ?: $info?->carrybee_invoice_id) : null,
            'paid_to' => $carrybeePaid ? ($order->carrybee_disbursed_to ?: null) : null, // vendor | platform
            'paid_at' => $paidAt ? (string) $paidAt : null,
        ];

        // 2. Dropshipper profit
        $reseller = $settlements->firstWhere('settlement_type', OrderSettlement::TYPE_RESELLER_PROFIT);
        $dropshipper = [
            'applicable' => !empty($order->user_id),
            'paid' => $reseller?->status === OrderSettlement::STATUS_SETTLED,
            'amount' => round((float) ($reseller?->net_payable_amount ?? $order->reseller_profit ?? 0), 2),
            'paid_at' => $reseller?->settled_at?->toDateTimeString(),
        ];

        // 3. Supplier (one row per vendor on multi-vendor orders)
        $supplierRows = $settlements
            ->where('settlement_type', OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE)
            ->where('status', '!=', OrderSettlement::STATUS_CANCELLED);
        $weReceive = $supplierRows->contains('direction', OrderSettlement::DIRECTION_PAYEE_OWES_PLATFORM);
        $supplier = [
            'applicable' => !empty($order->vendor_id) || $supplierRows->isNotEmpty(),
            'paid' => $supplierRows->isNotEmpty() && $supplierRows->every(fn ($s) => $s->status === OrderSettlement::STATUS_SETTLED),
            'amount' => round((float) $supplierRows->sum('net_payable_amount'), 2),
            // we_pay: platform pays the supplier; we_receive: supplier's own CarryBee
            // account got the cash, so we will get money from the supplier.
            'direction' => $weReceive ? 'we_receive' : 'we_pay',
            'paid_at' => $supplierRows->max('settled_at')?->toDateTimeString(),
        ];

        return [
            'stage' => $this->stage($status, $carrybee, $dropshipper, $supplier),
            'carrybee' => $carrybee,
            'dropshipper' => $dropshipper,
            'supplier' => $supplier,
        ];
    }

    private function stage(int $status, array $carrybee, array $dropshipper, array $supplier): string
    {
        if (in_array($status, self::CANCELLED_STATUSES, true)) {
            return 'cancelled';
        }
        if ($status === self::COMPLETED_STATUS) {
            return 'completed';
        }
        if ($status === self::RETURNING_STATUS) {
            return 'returning';
        }
        if (!in_array($status, self::DELIVERED_STATUSES, true)) {
            return 'not_delivered';
        }
        if ($carrybee['applicable'] && !$carrybee['paid']) {
            return 'carrybee_pending';
        }

        $dropDue = $dropshipper['applicable'] && !$dropshipper['paid'];
        $supDue = $supplier['applicable'] && !$supplier['paid'];

        return match (true) {
            $dropDue && $supDue => 'both_pending',
            $dropDue => 'dropshipper_pending',
            $supDue => 'supplier_pending',
            default => 'completed',
        };
    }

    /**
     * Server-side filter for the order list (?payment_stage=...), so pagination and
     * totals stay right. "dropshipper_pending" / "supplier_pending" include orders
     * where both are pending; "completed" is order status 9.
     */
    public function applyStageFilter(Builder $query, string $stage): void
    {
        $hasConsignment = fn (Builder $q) => $q->whereHas('deliveryInformation', fn ($d) => $d
            ->whereNotNull('consignment_id')->where('consignment_id', '!=', ''));
        $carrybeeNotPaid = fn (Builder $q) => $q
            ->whereNull('carrybee_paid_at')
            ->where(fn ($i) => $i->whereNull('carrybee_invoice_id')->orWhere('carrybee_invoice_id', ''));
        // CarryBee step done: status 10, no consignment, or CarryBee's payment recorded.
        $carrybeeDone = fn (Builder $q) => $q->where(fn ($w) => $w
            ->where('status', 10)
            ->orWhereDoesntHave('deliveryInformation', fn ($d) => $d->whereNotNull('consignment_id')->where('consignment_id', '!=', ''))
            ->orWhereNotNull('carrybee_paid_at')
            ->orWhere(fn ($i) => $i->whereNotNull('carrybee_invoice_id')->where('carrybee_invoice_id', '!=', '')));
        $notSettled = fn (string $type) => fn (Builder $q) => $q->whereDoesntHave('settlements', fn ($s) => $s
            ->where('settlement_type', $type)->where('status', OrderSettlement::STATUS_SETTLED));

        match ($stage) {
            'cancelled' => $query->whereIn('status', self::CANCELLED_STATUSES),
            'completed' => $query->where('status', self::COMPLETED_STATUS),
            'returning' => $query->where('status', self::RETURNING_STATUS),
            'not_delivered' => $query->whereNotIn('status', array_merge(
                self::DELIVERED_STATUSES, self::CANCELLED_STATUSES, [self::COMPLETED_STATUS, self::RETURNING_STATUS]
            )),
            'carrybee_pending' => $query->whereIn('status', [6, 13])->where($hasConsignment)->where($carrybeeNotPaid),
            'dropshipper_pending' => $query->whereIn('status', self::DELIVERED_STATUSES)->where($carrybeeDone)
                ->whereNotNull('user_id')->where($notSettled(OrderSettlement::TYPE_RESELLER_PROFIT)),
            'supplier_pending' => $query->whereIn('status', self::DELIVERED_STATUSES)->where($carrybeeDone)
                ->where($notSettled(OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE)),
            default => null,
        };
    }
}
