<?php

namespace App\Service;

use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\SettlementInvoice;

/**
 * "Completed" means everything about an order is done. This builds the checklist
 * the admin confirms before completing: delivery, CarryBee payment, courier
 * review, vendor invoice and both settlements.
 */
class OrderCompletionCheckService
{
    /**
     * @return array{all_done: bool, pending: array<int, string>, checks: array<int, array{key: string, label: string, required: bool, done: bool, detail: string}>}
     */
    public function check(Order $order): array
    {
        $order->loadMissing(['deliveryInformation', 'vendor', 'user']);
        $info = $order->deliveryInformation;
        $status = (int) $order->status;
        $hasConsignment = $order->hasConsignment();

        $settlements = OrderSettlement::where('order_id', $order->id)->get();
        $supplier = $settlements->firstWhere('settlement_type', OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE);
        $reseller = $settlements->firstWhere('settlement_type', OrderSettlement::TYPE_RESELLER_PROFIT);

        $checks = [];

        // 1. Delivered
        $delivered = in_array($status, [6, 9, 10, 13], true);
        $checks[] = $this->item('delivered', 'Order delivered to the customer', true, $delivered,
            $delivered ? 'Delivered' : 'Current status is not Delivered / Carrybee Paid');

        // 2. CarryBee paid (only when a consignment exists)
        $carrybeePaid = (bool) $order->is_carrybee_paid;
        $checks[] = $this->item('carrybee_paid', 'CarryBee paid the COD', $hasConsignment, $carrybeePaid,
            !$hasConsignment ? 'No CarryBee consignment for this order'
                : ($carrybeePaid ? 'Invoice ' . ($order->carrybee_invoice_id ?: '—') . ($order->carrybee_paid_at ? ' on ' . $order->carrybee_paid_at : '')
                    : 'CarryBee has not confirmed payment yet'));

        // 3. Courier review (partial delivery / amount mismatch)
        $needsReview = (bool) ($info?->needs_review);
        $checks[] = $this->item('courier_review', 'Courier review cleared', $needsReview, !$needsReview,
            $needsReview ? 'Waiting for review: ' . ($info->review_reason ?: 'amount mismatch') : 'No review needed');

        // 4. Vendor invoice (vendor collected the COD in its own CarryBee account and owes the platform)
        $ownAccount = (bool) ($order->vendor?->has_own_delivery_account) && $hasConsignment;
        $invoice = $ownAccount ? $this->vendorInvoiceFor($order) : null;
        $invoiceDone = $invoice && in_array($invoice->status, [SettlementInvoice::STATUS_APPROVED], true);
        $checks[] = $this->item('vendor_invoice', 'Vendor invoice paid and approved', $ownAccount, (bool) $invoiceDone,
            !$ownAccount ? 'Not needed: platform CarryBee account'
                : (!$invoice ? 'No vendor invoice includes this order yet'
                    : 'Invoice ' . $invoice->invoice_number . ' is ' . $invoice->status));

        // 5. Vendor (supplier) settlement
        $hasVendor = !empty($order->vendor_id);
        $supplierDone = $supplier && $supplier->status === OrderSettlement::STATUS_SETTLED;
        $checks[] = $this->item('vendor_settlement', 'Vendor settlement done', $hasVendor, (bool) $supplierDone,
            !$hasVendor ? 'No vendor on this order' : $this->settlementDetail($supplier));

        // 6. Dropshipper profit settlement
        $hasReseller = !empty($order->user_id);
        $resellerDone = $reseller && $reseller->status === OrderSettlement::STATUS_SETTLED;
        $checks[] = $this->item('dropshipper_settlement', 'Dropshipper profit paid', $hasReseller, (bool) $resellerDone,
            !$hasReseller ? 'No dropshipper on this order' : $this->settlementDetail($reseller));

        $pending = array_values(array_map(
            fn ($c) => $c['label'],
            array_filter($checks, fn ($c) => $c['required'] && !$c['done'])
        ));

        return ['all_done' => $pending === [], 'pending' => $pending, 'checks' => $checks];
    }

    private function item(string $key, string $label, bool $required, bool $done, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'required' => $required, 'done' => $required ? $done : true, 'detail' => $detail];
    }

    private function settlementDetail(?OrderSettlement $settlement): string
    {
        if (!$settlement) {
            return 'Settlement not created yet';
        }
        $amount = number_format((float) ($settlement->net_payable_amount ?? $settlement->settleable_amount ?? 0), 2);

        return $settlement->status === OrderSettlement::STATUS_SETTLED
            ? "Settled ৳{$amount}" . ($settlement->settled_trx_id ? " (trx {$settlement->settled_trx_id})" : '')
            : "৳{$amount} is {$settlement->status}";
    }

    /** Latest non-rejected vendor invoice that lists this order. */
    private function vendorInvoiceFor(Order $order): ?SettlementInvoice
    {
        return SettlementInvoice::where('vendor_id', $order->vendor_id)
            ->where('status', '!=', SettlementInvoice::STATUS_REJECTED)
            ->orderByDesc('id')
            ->get()
            ->first(fn (SettlementInvoice $invoice) => in_array((int) $order->id, array_map('intval', (array) $invoice->order_ids), true));
    }
}
