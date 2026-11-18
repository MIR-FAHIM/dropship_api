<?php

namespace App\Service;

use App\Models\SettlementInvoice;
use Illuminate\Support\Collection;

/**
 * Adds `invoices` to each order in a list, so the panels can flag invoiced orders:
 *
 *   invoices.supplier    vendor settlement invoice (supplier side)   — admin only
 *   invoices.dropshipper dropshipper profit payout invoice
 *
 * Each is null or {id, invoice_number, status, paid_at, created_at}. Cancelled and
 * rejected invoices don't count (their orders can be invoiced again).
 *
 * Invoices keep their orders in a JSON `order_ids` list, so this loads the page's
 * dropshippers' and vendors' invoices in one query and matches them here.
 */
class OrderInvoiceFlags
{
    public const SUPPLIER = 'supplier';
    public const DROPSHIPPER = 'dropshipper';

    /** @param Collection $orders Order models (or arrays with id, user_id, vendor_id). */
    public function attach(Collection $orders, array $kinds = [self::SUPPLIER, self::DROPSHIPPER]): void
    {
        if ($orders->isEmpty()) {
            return;
        }

        $dropshipperIds = in_array(self::DROPSHIPPER, $kinds, true)
            ? $orders->pluck('user_id')->filter()->unique()->values()->all() : [];
        $vendorIds = in_array(self::SUPPLIER, $kinds, true)
            ? $orders->pluck('vendor_id')->filter()->unique()->values()->all() : [];

        $byOrder = [];
        if ($dropshipperIds || $vendorIds) {
            SettlementInvoice::query()
                ->whereNotIn('status', [SettlementInvoice::STATUS_CANCELLED, SettlementInvoice::STATUS_REJECTED])
                ->where(function ($q) use ($dropshipperIds, $vendorIds) {
                    if ($dropshipperIds) {
                        $q->orWhere(fn ($d) => $d->where('type', SettlementInvoice::TYPE_DROPSHIPPER_PAYOUT)->whereIn('dropshipper_id', $dropshipperIds));
                    }
                    if ($vendorIds) {
                        $q->orWhere(fn ($v) => $v->where('type', SettlementInvoice::TYPE_VENDOR_SETTLEMENT)->whereIn('vendor_id', $vendorIds));
                    }
                })
                ->orderBy('id') // newest wins below
                ->get(['id', 'invoice_number', 'type', 'status', 'order_ids', 'paid_at', 'created_at'])
                ->each(function (SettlementInvoice $invoice) use (&$byOrder) {
                    $kind = $invoice->type === SettlementInvoice::TYPE_DROPSHIPPER_PAYOUT ? self::DROPSHIPPER : self::SUPPLIER;
                    foreach ((array) $invoice->order_ids as $orderId) {
                        $byOrder[(int) $orderId][$kind] = [
                            'id' => $invoice->id,
                            'invoice_number' => $invoice->invoice_number,
                            'status' => $invoice->status,
                            'paid_at' => $invoice->paid_at?->toIso8601String(),
                            'created_at' => $invoice->created_at?->toIso8601String(),
                        ];
                    }
                });
        }

        foreach ($orders as $order) {
            $flags = [];
            foreach ($kinds as $kind) {
                $flags[$kind] = $byOrder[(int) data_get($order, 'id')][$kind] ?? null;
            }
            $order->setAttribute('invoices', $flags);
        }
    }
}
