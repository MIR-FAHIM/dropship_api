<?php

namespace App\Service;

use App\Models\OrderSettlement;
use App\Models\Transaction;
use App\Models\WithdrawRequest;

class CompanyTransactionService
{
    public function recordSettlement(OrderSettlement $settlement): void
    {
        if ($settlement->status !== OrderSettlement::STATUS_SETTLED) {
            return;
        }

        if (
            $settlement->user_type === 'vendor'
            && $settlement->settlement_type === OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE
        ) {
            $this->recordVendorSettlement($settlement);
            return;
        }

        if (
            $settlement->user_type === 'shipping'
            && $settlement->settlement_type === OrderSettlement::TYPE_SHIPPING_CHARGE
        ) {
            $this->recordShippingCharge($settlement);
        }
    }

    public function recordWithdrawApproval(WithdrawRequest $withdraw, ?string $trxId = null): void
    {
        if ($withdraw->status !== 'approved') {
            return;
        }

        $amount = round((float) $withdraw->amount, 2);
        if ($amount <= 0) {
            return;
        }

        $this->recordCompleted([
            'amount' => $amount,
            'ref_id' => $withdraw->id,
            'trx_id' => $trxId ?: 'WD-' . $withdraw->id,
            'trx_type' => 'debit',
            'source' => 'withdraw',
            'order_id' => null,
            'type' => 'reseller_withdraw',
            'note' => 'Debit transaction for approved reseller withdraw #' . $withdraw->id,
        ]);
    }

    private function recordVendorSettlement(OrderSettlement $settlement): void
    {
        $amount = round((float) $settlement->settleable_amount, 2);
        if ($amount <= 0) {
            return;
        }

        $settlement->loadMissing('order');
        $orderNumber = $settlement->order?->order_number ?: $settlement->order_id;

        $this->recordCompleted([
            'amount' => $amount,
            'ref_id' => $settlement->id,
            'trx_id' => $settlement->settled_trx_id ?: $settlement->trx_id,
            'trx_type' => 'debit',
            'source' => 'vendor_settlement',
            'order_id' => $settlement->order_id,
            'type' => 'vendor_settlement',
            'note' => 'Debit transaction for vendor settlement #' . $settlement->id . ' order #' . $orderNumber,
        ]);
    }

    private function recordShippingCharge(OrderSettlement $settlement): void
    {
        $amount = round((float) $settlement->settleable_amount, 2);
        if ($amount <= 0) {
            return;
        }

        $settlement->loadMissing('order');
        $orderNumber = $settlement->order?->order_number ?: $settlement->order_id;

        $this->recordCompleted([
            'amount' => $amount,
            'ref_id' => $settlement->id,
            'trx_id' => $settlement->trx_id,
            'trx_type' => 'debit',
            'source' => 'delivery_company',
            'order_id' => $settlement->order_id,
            'type' => 'shipping_charge',
            'note' => 'Debit transaction for delivery company charge settlement #' . $settlement->id . ' order #' . $orderNumber,
        ]);
    }

    private function recordCompleted(array $data): void
    {
        Transaction::updateOrCreate(
            [
                'ref_id' => (string) $data['ref_id'],
                'type' => $data['type'],
                'trx_type' => $data['trx_type'],
            ],
            [
                'amount' => $data['amount'],
                'trx_id' => $data['trx_id'],
                'status' => 'completed',
                'source' => $data['source'],
                'order_id' => $data['order_id'],
                'note' => $data['note'],
            ]
        );
    }
}
