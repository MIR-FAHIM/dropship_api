<?php

namespace App\Service;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSettlement;
use App\Models\OrderAccountingLedger;
use App\Models\ResellerTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WithdrawRequest;
use App\Models\WalletTopup;
use App\Models\SettlementInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderAccountingService
{
    /**
     * Process financial booking for an order.
     */
    public function processOrderBooking(Order $order, array $advanceData = []): void
    {
        $advanceAmount = isset($advanceData['amount']) ? round((float) $advanceData['amount'], 2) : 0.00;
        $advanceStatus = $advanceData['status'] ?? ($advanceAmount > 0 ? 'approved' : 'none');
        $advanceRecipient = $advanceData['recipient'] ?? ($advanceAmount > 0 ? 'platform' : 'none');
        $advancePaymentMethod = $advanceData['payment_method'] ?? null;
        $advanceTrxId = $advanceData['trx_id'] ?? null;
        $advanceSenderPhone = $advanceData['sender_phone'] ?? null;

        $totalOrderAmount = round((float) $order->total, 2);
        $remainingCod = max(0.00, round($totalOrderAmount - $advanceAmount, 2));

        // Determine if vendor has own delivery account
        $courierOwner = 'platform';
        $order->loadMissing('items.product.vendor');
        foreach ($order->items as $item) {
            $vendor = $item->product?->vendor;
            if ($vendor && $vendor->has_own_delivery_account) {
                $courierOwner = 'vendor';
                break;
            }
        }

        $order->update([
            'advance_amount' => $advanceAmount,
            'advance_status' => $advanceStatus,
            'advance_recipient' => $advanceRecipient,
            'advance_payment_method' => $advancePaymentMethod,
            'advance_trx_id' => $advanceTrxId,
            'advance_sender_phone' => $advanceSenderPhone,
            'remaining_cod' => $remainingCod,
            'courier_account_owner' => $courierOwner,
        ]);

        // Post Booking Ledger
        DB::transaction(function () use ($order, $totalOrderAmount, $advanceAmount, $remainingCod, $advanceRecipient, $advanceStatus) {
            if ($advanceAmount > 0 && $advanceStatus === 'approved') {
                // Platform holds advance taken from reseller/customer
                OrderAccountingLedger::create([
                    'order_id' => $order->id,
                    'entry_type' => 'booking',
                    'account_code' => 'cash_platform',
                    'party_type' => 'platform',
                    'party_id' => null,
                    'debit' => $advanceAmount,
                    'credit' => 0.00,
                    'currency' => 'BDT',
                    'reference_id' => $order->advance_trx_id,
                    'description' => "Customer advance received for order #{$order->id} via {$order->advance_payment_method}",
                ]);
            }

            if ($remainingCod > 0) {
                // Expected COD collection by courier
                OrderAccountingLedger::create([
                    'order_id' => $order->id,
                    'entry_type' => 'booking',
                    'account_code' => 'courier_clearing',
                    'party_type' => 'courier',
                    'party_id' => null,
                    'debit' => $remainingCod,
                    'credit' => 0.00,
                    'currency' => 'BDT',
                    'description' => "Expected COD collection by courier for order #{$order->id}",
                ]);
            }

            // Total Order Booking Value
            OrderAccountingLedger::create([
                'order_id' => $order->id,
                'entry_type' => 'booking',
                'account_code' => 'order_revenue_clearing',
                'party_type' => 'customer',
                'party_id' => $order->user_id,
                'debit' => 0.00,
                'credit' => $totalOrderAmount,
                'currency' => 'BDT',
                'description' => "Order booking value for order #{$order->id}",
            ]);
        });
    }

    /**
     * Unified, transactional processing when an order is completed/delivered.
     */
    public function processOrderDelivery(Order $order, ?array $courierFeeData = null): array
    {
        return DB::transaction(function () use ($order, $courierFeeData) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::lockForUpdate()->find($order->id);

            // Guard against duplicate execution
            $alreadyProcessed = OrderAccountingLedger::where('order_id', $lockedOrder->id)
                ->where('entry_type', 'delivery_completed')
                ->exists();

            if ($alreadyProcessed) {
                return [
                    'success' => true,
                    'message' => 'Order delivery accounting already processed.',
                    'settlements' => OrderSettlement::where('order_id', $lockedOrder->id)->get(),
                ];
            }

            $lockedOrder->loadMissing(['items.product.vendor', 'user']);

            // 1. Courier Fees Resolution
            $customerShippingCharge = round((float) ($lockedOrder->shipping_fee ?? 0.00), 2);
            $actualDeliveryFee = isset($courierFeeData['actual_courier_fee']) 
                ? round((float) $courierFeeData['actual_courier_fee'], 2) 
                : ($lockedOrder->actual_courier_fee ?: $customerShippingCharge);
            $actualCodFee = isset($courierFeeData['actual_cod_fee']) 
                ? round((float) $courierFeeData['actual_cod_fee'], 2) 
                : ($lockedOrder->actual_cod_fee ?: 0.00);

            $actualCourierCost = round($actualDeliveryFee + $actualCodFee, 2);
            $isConfirmedCost = isset($courierFeeData['confirmed']) ? (bool) $courierFeeData['confirmed'] : true;

            $remainingCod = round((float) ($lockedOrder->remaining_cod ?? max(0, $lockedOrder->total - ($lockedOrder->advance_amount ?? 0))), 2);
            $courierNetPayout = max(0.00, round($remainingCod - $actualCourierCost, 2));

            // Courier account owner
            $courierOwner = $lockedOrder->courier_account_owner ?: 'platform';
            foreach ($lockedOrder->items as $item) {
                if ($item->product?->vendor?->has_own_delivery_account) {
                    $courierOwner = 'vendor';
                    break;
                }
            }

            $lockedOrder->update([
                'actual_courier_fee' => $actualDeliveryFee,
                'actual_cod_fee' => $actualCodFee,
                'actual_courier_cost' => $actualCourierCost,
                'courier_cost_confirmed' => $isConfirmedCost,
                'courier_net_payout' => $courierNetPayout,
                'courier_account_owner' => $courierOwner,
                'courier_payout_status' => $courierFeeData['payout_status'] ?? 'received',
            ]);

            // 2. Platform Delivery Margin (customer shipping - actual courier cost belongs to ResellerBrain)
            $platformShippingMargin = round($customerShippingCharge - $actualCourierCost, 2);

            // 3. Entitlements Calculation per vendor / reseller / platform
            $vendorEntitlements = [];
            $totalVendorEntitlement = 0.00;
            $totalResellerProfit = 0.00;
            $totalPlatformProductMargin = 0.00;

            foreach ($lockedOrder->items as $item) {
                $qty = (int) ($item->qty ?? $item->quantity ?? 1);
                $product = $item->product;

                $vendorPrice = round((float) ($item->unit_price ?? $product?->unit_price ?? 0), 2);
                $adminPrice = round((float) ($item->admin_price ?? $product?->admin_price ?? $vendorPrice), 2);
                $sellingPrice = round((float) ($item->reseller_price ?? $item->price ?? $adminPrice), 2);

                $itemVendorTotal = round($vendorPrice * $qty, 2);
                $itemResellerProfit = round(($sellingPrice - $adminPrice) * $qty, 2);
                $itemPlatformMargin = round(($adminPrice - $vendorPrice) * $qty, 2);

                $vendorId = $product?->vendor_id ?: $lockedOrder->vendor_id;
                $vendorUserId = $product?->vendor?->user_id;

                if (!isset($vendorEntitlements[$vendorId])) {
                    $vendorEntitlements[$vendorId] = [
                        'vendor_id' => $vendorId,
                        'user_id' => $vendorUserId,
                        'gross_amount' => 0.00,
                    ];
                }
                $vendorEntitlements[$vendorId]['gross_amount'] += $itemVendorTotal;

                $totalVendorEntitlement += $itemVendorTotal;
                $totalResellerProfit += $itemResellerProfit;
                $totalPlatformProductMargin += $itemPlatformMargin;
            }

            $totalVendorEntitlement = round($totalVendorEntitlement, 2);
            $totalResellerProfit = round($totalResellerProfit, 2);
            $totalPlatformProductMargin = round($totalPlatformProductMargin, 2);

            // 4. Handle Vendor Courier Net Payout vs Platform Remittance
            // If vendor-owned courier: courier remits $courierNetPayout directly to vendor.
            // Platform remittance to vendor is ONLY the remaining shortage!
            $createdSettlements = [];

            foreach ($vendorEntitlements as $vId => $vData) {
                $grossEntitlement = round($vData['gross_amount'], 2);
                $deductedByCourier = 0.00;
                $netPayable = $grossEntitlement;

                if ($courierOwner === 'vendor') {
                    // Courier payout went directly to vendor
                    $deductedByCourier = min($grossEntitlement, $courierNetPayout);
                    $netPayable = max(0.00, round($grossEntitlement - $deductedByCourier, 2));
                }

                $settlement = OrderSettlement::updateOrCreate(
                    [
                        'order_id' => $lockedOrder->id,
                        'settlement_type' => OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE,
                        'vendor_id' => $vId,
                    ],
                    [
                        'payable_user_id' => $vData['user_id'],
                        'user_type' => 'vendor',
                        'gross_entitlement' => $grossEntitlement,
                        'deducted_by_courier' => $deductedByCourier,
                        'advance_deduction' => 0.00,
                        'settleable_amount' => $netPayable,
                        'net_payable_amount' => $netPayable,
                        'status' => $netPayable <= 0.00 ? OrderSettlement::STATUS_SETTLED : OrderSettlement::STATUS_PENDING,
                        'settled_at' => $netPayable <= 0.00 ? now() : null,
                        'admin_note' => $courierOwner === 'vendor' 
                            ? "Vendor-owned delivery: ৳{$deductedByCourier} paid by courier. Shortage payable: ৳{$netPayable}."
                            : "Platform delivery: Gross payable ৳{$netPayable}.",
                        'trx_id' => 'SETTLE-V-' . $lockedOrder->id . '-' . $vId . '-' . time(),
                    ]
                );
                $createdSettlements[] = $settlement;
            }

            // 5. Reseller Profit Settlement
            $resellerSettlement = OrderSettlement::updateOrCreate(
                [
                    'order_id' => $lockedOrder->id,
                    'settlement_type' => OrderSettlement::TYPE_RESELLER_PROFIT,
                ],
                [
                    'payable_user_id' => $lockedOrder->user_id,
                    'user_type' => 'dropshipper',
                    'gross_entitlement' => $totalResellerProfit,
                    'settleable_amount' => $totalResellerProfit,
                    'net_payable_amount' => $totalResellerProfit,
                    'status' => OrderSettlement::STATUS_PENDING,
                    'admin_note' => "Reseller profit for order #{$lockedOrder->id}",
                    'trx_id' => 'SETTLE-R-' . $lockedOrder->id . '-' . time(),
                ]
            );
            $createdSettlements[] = $resellerSettlement;

            // 6. Platform Logistic Earning (Shipping Margin)
            $createdSettlements[] = OrderSettlement::updateOrCreate(
                [
                    'order_id' => $lockedOrder->id,
                    'settlement_type' => OrderSettlement::TYPE_COMPANY_LOGISTIC_EARNING,
                ],
                [
                    'payable_user_id' => null,
                    'user_type' => 'admin',
                    'gross_entitlement' => $platformShippingMargin,
                    'settleable_amount' => $platformShippingMargin,
                    'net_payable_amount' => $platformShippingMargin,
                    'status' => OrderSettlement::STATUS_SETTLED,
                    'settled_at' => now(),
                    'admin_note' => "Platform shipping margin: ৳{$customerShippingCharge} customer charge - ৳{$actualCourierCost} courier cost = ৳{$platformShippingMargin}",
                    'trx_id' => 'SETTLE-ADM-SHIP-' . $lockedOrder->id . '-' . time(),
                ]
            );

            // 7. Platform Product Commission
            if ($totalPlatformProductMargin > 0) {
                $createdSettlements[] = OrderSettlement::updateOrCreate(
                    [
                        'order_id' => $lockedOrder->id,
                        'settlement_type' => OrderSettlement::TYPE_COMPANY_PRODUCT_COMMISSION,
                    ],
                    [
                        'payable_user_id' => null,
                        'user_type' => 'admin',
                        'gross_entitlement' => $totalPlatformProductMargin,
                        'settleable_amount' => $totalPlatformProductMargin,
                        'net_payable_amount' => $totalPlatformProductMargin,
                        'status' => OrderSettlement::STATUS_SETTLED,
                        'settled_at' => now(),
                        'admin_note' => "Platform product commission for order #{$lockedOrder->id}",
                        'trx_id' => 'SETTLE-ADM-PROD-' . $lockedOrder->id . '-' . time(),
                    ]
                );
            }

            // 8. Balanced Ledger Entries (Debits == Credits)
            // Dr Courier Clearing (COD received)
            // Release booking revenue clearing upon delivery
            OrderAccountingLedger::create([
                'order_id' => $lockedOrder->id,
                'entry_type' => 'delivery_completed',
                'account_code' => 'order_revenue_clearing',
                'party_type' => 'customer',
                'party_id' => $lockedOrder->user_id,
                'debit' => (float) $lockedOrder->total,
                'credit' => 0.00,
                'currency' => 'BDT',
                'description' => "Release booking clearing upon delivery for order #{$lockedOrder->id}",
            ]);

            // Deduct actual courier cost from courier clearing
            if ($actualCourierCost > 0) {
                OrderAccountingLedger::create([
                    'order_id' => $lockedOrder->id,
                    'entry_type' => 'delivery_completed',
                    'account_code' => 'courier_clearing',
                    'party_type' => 'courier',
                    'party_id' => null,
                    'debit' => 0.00,
                    'credit' => $actualCourierCost,
                    'currency' => 'BDT',
                    'description' => "Deduct actual courier cost from clearing for order #{$lockedOrder->id}",
                ]);
            }

            // Vendor Payable
            OrderAccountingLedger::create([
                'order_id' => $lockedOrder->id,
                'entry_type' => 'delivery_completed',
                'account_code' => 'vendor_payable',
                'party_type' => 'vendor',
                'party_id' => $lockedOrder->vendor_id,
                'debit' => 0.00,
                'credit' => $totalVendorEntitlement,
                'currency' => 'BDT',
                'description' => "Vendor gross entitlement for order #{$lockedOrder->id}",
            ]);

            if ($courierOwner === 'vendor' && $courierNetPayout > 0) {
                // Deduct courier payout received directly by vendor
                OrderAccountingLedger::create([
                    'order_id' => $lockedOrder->id,
                    'entry_type' => 'courier_payout',
                    'account_code' => 'vendor_payable',
                    'party_type' => 'vendor',
                    'party_id' => $lockedOrder->vendor_id,
                    'debit' => $courierNetPayout,
                    'credit' => 0.00,
                    'currency' => 'BDT',
                    'description' => "Direct courier payout of ৳{$courierNetPayout} received by vendor",
                ]);

                OrderAccountingLedger::create([
                    'order_id' => $lockedOrder->id,
                    'entry_type' => 'courier_payout',
                    'account_code' => 'courier_clearing',
                    'party_type' => 'courier',
                    'party_id' => null,
                    'debit' => 0.00,
                    'credit' => $courierNetPayout,
                    'currency' => 'BDT',
                    'description' => "Direct courier payout of ৳{$courierNetPayout} cleared from courier",
                ]);
            } elseif ($courierOwner === 'platform' && $courierNetPayout > 0) {
                // Courier pays net payout to platform
                OrderAccountingLedger::create([
                    'order_id' => $lockedOrder->id,
                    'entry_type' => 'courier_payout',
                    'account_code' => 'cash_platform',
                    'party_type' => 'platform',
                    'party_id' => null,
                    'debit' => $courierNetPayout,
                    'credit' => 0.00,
                    'currency' => 'BDT',
                    'description' => "Net courier payout of ৳{$courierNetPayout} received by platform",
                ]);

                OrderAccountingLedger::create([
                    'order_id' => $lockedOrder->id,
                    'entry_type' => 'courier_payout',
                    'account_code' => 'courier_clearing',
                    'party_type' => 'courier',
                    'party_id' => null,
                    'debit' => 0.00,
                    'credit' => $courierNetPayout,
                    'currency' => 'BDT',
                    'description' => "Net courier payout of ৳{$courierNetPayout} cleared from courier",
                ]);
            }

            // Reseller Payable
            if ($totalResellerProfit > 0) {
                OrderAccountingLedger::create([
                    'order_id' => $lockedOrder->id,
                    'entry_type' => 'delivery_completed',
                    'account_code' => 'reseller_payable',
                    'party_type' => 'reseller',
                    'party_id' => $lockedOrder->user_id,
                    'debit' => 0.00,
                    'credit' => $totalResellerProfit,
                    'currency' => 'BDT',
                    'description' => "Reseller profit entitlement for order #{$lockedOrder->id}",
                ]);
            }

            // Platform Revenue (Product margin + Shipping margin)
            if ($totalPlatformProductMargin > 0) {
                OrderAccountingLedger::create([
                    'order_id' => $lockedOrder->id,
                    'entry_type' => 'delivery_completed',
                    'account_code' => 'platform_margin_product',
                    'party_type' => 'platform',
                    'party_id' => null,
                    'debit' => 0.00,
                    'credit' => $totalPlatformProductMargin,
                    'currency' => 'BDT',
                    'description' => "Platform product margin for order #{$lockedOrder->id}",
                ]);
            }

            OrderAccountingLedger::create([
                'order_id' => $lockedOrder->id,
                'entry_type' => 'delivery_completed',
                'account_code' => 'platform_margin_shipping',
                'party_type' => 'platform',
                'party_id' => null,
                'debit' => 0.00,
                'credit' => $platformShippingMargin,
                'currency' => 'BDT',
                'description' => "Platform shipping margin for order #{$lockedOrder->id}",
            ]);

            // 9. Update Company Transaction (authoritative platform earnings)
            $totalPlatformEarnings = round($totalPlatformProductMargin + $platformShippingMargin, 2);
            if ($totalPlatformEarnings > 0) {
                Transaction::updateOrCreate(
                    [
                        'order_id' => $lockedOrder->id,
                        'type' => 'platform_order_margin',
                    ],
                    [
                        'amount' => $totalPlatformEarnings,
                        'trx_id' => 'PL-MRG-' . $lockedOrder->id . '-' . time(),
                        'trx_type' => 'credit',
                        'source' => 'order_margin',
                        'status' => 'completed',
                        'note' => "Platform margin (Product: ৳{$totalPlatformProductMargin}, Shipping: ৳{$platformShippingMargin}) for order #{$lockedOrder->id}",
                    ]
                );
            }

            return [
                'success' => true,
                'message' => 'Order delivery accounting processed successfully.',
                'settlements' => $createdSettlements,
                'platform_shipping_margin' => $platformShippingMargin,
                'total_vendor_entitlement' => $totalVendorEntitlement,
                'courier_net_payout' => $courierNetPayout,
            ];
        });
    }

    /**
     * Reverse delivery accounting if an order moves back from delivered.
     */
    public function processOrderReversal(Order $order, string $reason = 'Status reversal'): void
    {
        DB::transaction(function () use ($order, $reason) {
            $deliveryLedgers = OrderAccountingLedger::where('order_id', $order->id)
                ->where('entry_type', 'delivery_completed')
                ->get();

            if ($deliveryLedgers->isEmpty()) {
                return;
            }

            foreach ($deliveryLedgers as $ledger) {
                OrderAccountingLedger::create([
                    'order_id' => $order->id,
                    'entry_type' => 'reversal',
                    'account_code' => $ledger->account_code,
                    'party_type' => $ledger->party_type,
                    'party_id' => $ledger->party_id,
                    'debit' => $ledger->credit, // Flip debit and credit
                    'credit' => $ledger->debit,
                    'currency' => $ledger->currency,
                    'description' => "Reversal: {$reason} (Original ledger #{$ledger->id})",
                ]);
            }

            // Mark settlements as cancelled
            OrderSettlement::where('order_id', $order->id)->update([
                'status' => OrderSettlement::STATUS_CANCELLED,
                'admin_note' => DB::raw("CONCAT(COALESCE(admin_note, ''), ' [Reversed: " . addslashes($reason) . "]')"),
            ]);

            // Revert company transaction
            Transaction::where('order_id', $order->id)->where('type', 'platform_order_margin')->update([
                'status' => 'reversed',
            ]);
        });
    }

    /**
     * Calculate comprehensive, real-time balances for a reseller.
     */
    public function calculateUserBalances(int $userId): array
    {
        $credit = (float) ResellerTransaction::where('reseller_id', $userId)
            ->where('status', 'completed')
            ->where('trx_type', 'credit')
            ->sum('amount');

        $debit = (float) ResellerTransaction::where('reseller_id', $userId)
            ->where('status', 'completed')
            ->where('trx_type', 'debit')
            ->sum('amount');

        // Pending reservations in withdraw requests
        $pendingReserved = (float) WithdrawRequest::where('user_id', $userId)
            ->where('status', 'pending')
            ->sum('amount');

        $netBalance = $credit - $debit;
        $availableBalance = max(0.00, round($netBalance - $pendingReserved, 2));

        return [
            'total_credit' => round($credit, 2),
            'total_debit' => round($debit, 2),
            'net_balance' => round($netBalance, 2),
            'pending_reserved' => round($pendingReserved, 2),
            'available_balance' => $availableBalance,
        ];
    }

    /**
     * Reserve balance for a new withdraw request.
     */
    public function reserveWithdrawal(int $userId, float $amount, ?string $type = null, ?int $bankId = null, ?string $note = null): WithdrawRequest
    {
        return DB::transaction(function () use ($userId, $amount, $type, $bankId, $note) {
            User::lockForUpdate()->findOrFail($userId);

            $balances = $this->calculateUserBalances($userId);

            if ($amount > $balances['available_balance']) {
                throw ValidationException::withMessages([
                    'amount' => ["Insufficient available balance. You have ৳{$balances['available_balance']} available (৳{$balances['pending_reserved']} is currently pending in other requests)."],
                ]);
            }

            $paymentMethod = 'bank';
            if ($bankId) {
                $bank = \App\Models\UserBankAccount::find($bankId);
                $paymentMethod = (string) ($bank?->payment_method_id ?: 'bank');
            }

            return WithdrawRequest::create([
                'user_id' => $userId,
                'amount' => $amount,
                'reserved_amount' => $amount,
                'status' => 'pending',
                'payment_method' => $paymentMethod,
                'bank_id' => $bankId,
                'note' => $note,
                'type' => $type,
            ]);
        });
    }

    /**
     * Complete a withdrawal transfer with bank transaction reference.
     */
    public function transferWithdrawal(WithdrawRequest $withdraw, string $bankReference): WithdrawRequest
    {
        return DB::transaction(function () use ($withdraw, $bankReference) {
            $withdraw = WithdrawRequest::lockForUpdate()->findOrFail($withdraw->id);

            if ($withdraw->status === 'transferred') {
                return $withdraw;
            }

            $withdraw->update([
                'status' => 'transferred',
                'bank_reference' => $bankReference,
                'transferred_at' => now(),
            ]);

            $trxId = 'WD-' . $withdraw->id . '-' . time();

            // Record completed debit in ResellerTransaction
            ResellerTransaction::updateOrCreate(
                [
                    'reseller_id' => $withdraw->user_id,
                    'ref_id' => $withdraw->id,
                    'type' => 'withdraw',
                ],
                [
                    'amount' => $withdraw->amount,
                    'trx_id' => $trxId,
                    'trx_type' => 'debit',
                    'note' => "Withdraw transferred via bank/MFS (Ref: {$bankReference})",
                    'status' => 'completed',
                    'source' => 'withdraw',
                ]
            );

            // Record Company Transaction debit
            Transaction::updateOrCreate(
                [
                    'ref_id' => $withdraw->id,
                    'type' => 'reseller_withdraw',
                ],
                [
                    'amount' => $withdraw->amount,
                    'trx_id' => $trxId,
                    'trx_type' => 'debit',
                    'source' => 'withdraw',
                    'status' => 'completed',
                    'note' => "Payout for withdraw #{$withdraw->id} (Ref: {$bankReference})",
                ]
            );

            return $withdraw;
        });
    }

    /**
     * Reject a withdrawal request, releasing reserved funds.
     */
    public function rejectWithdrawal(WithdrawRequest $withdraw, ?string $reason = null): WithdrawRequest
    {
        return DB::transaction(function () use ($withdraw, $reason) {
            $withdraw = WithdrawRequest::lockForUpdate()->findOrFail($withdraw->id);

            $withdraw->update([
                'status' => 'rejected',
                'reserved_amount' => 0.00,
                'note' => $reason ? ($withdraw->note . " [Rejected: {$reason}]") : $withdraw->note,
            ]);

            return $withdraw;
        });
    }

    /**
     * Check if a dropshipper requires advance payment (< 5 completed orders).
     */
    public function checkAdvanceEligibility(int $userId): array
    {
        $completedCount = Order::where('user_id', $userId)
            ->where(function ($q) {
                $q->where('status', 9) // delivered/completed
                  ->orWhereHas('orderStatus', function ($sub) {
                      $sub->whereIn('type', ['delivered', 'completed']);
                  });
            })
            ->count();

        $requiresAdvance = ($completedCount < 5);
        $defaultAdvanceAmount = 130.00;

        return [
            'requires_advance' => $requiresAdvance,
            'completed_orders_count' => $completedCount,
            'min_orders_threshold' => 5,
            'default_advance_amount' => $defaultAdvanceAmount,
        ];
    }

    /**
     * Pay advance directly from dropshipper wallet balance.
     */
    public function payAdvanceFromWallet(Order $order, float $amount): bool
    {
        return DB::transaction(function () use ($order, $amount) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $balances = $this->calculateUserBalances($order->user_id);

            if ($amount > $balances['available_balance']) {
                throw ValidationException::withMessages([
                    'amount' => ["Insufficient available balance. You have ৳{$balances['available_balance']} available."],
                ]);
            }

            $totalOrderAmount = round((float) $order->total, 2);
            $remainingCod = max(0.00, round($totalOrderAmount - $amount, 2));

            $trxId = 'WLT-ADV-' . $order->id . '-' . time();

            $order->update([
                'advance_amount' => $amount,
                'advance_status' => 'approved',
                'advance_recipient' => 'platform',
                'advance_payment_method' => 'wallet',
                'advance_trx_id' => $trxId,
                'advance_verified_at' => now(),
                'remaining_cod' => $remainingCod,
            ]);

            // Record completed debit in ResellerTransaction
            ResellerTransaction::create([
                'reseller_id' => $order->user_id,
                'order_id' => $order->id,
                'amount' => $amount,
                'trx_type' => 'debit',
                'type' => 'order_advance',
                'source' => 'wallet',
                'trx_id' => $trxId,
                'status' => 'completed',
                'note' => "Advance delivery fee for order #{$order->order_number} paid from wallet",
            ]);

            // Post to platform ledger
            OrderAccountingLedger::create([
                'order_id' => $order->id,
                'entry_type' => 'booking',
                'account_code' => 'cash_platform',
                'party_type' => 'platform',
                'party_id' => null,
                'debit' => $amount,
                'credit' => 0.00,
                'currency' => 'BDT',
                'reference_id' => $trxId,
                'description' => "Advance received from reseller wallet for order #{$order->id}",
            ]);

            return true;
        });
    }

    /**
     * Submit advance via MFS (bKash/Nagad) pending Admin verification.
     */
    public function submitMfsAdvance(Order $order, float $amount, string $method, string $trxId, ?string $senderPhone = null): Order
    {
        return DB::transaction(function () use ($order, $amount, $method, $trxId, $senderPhone) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $totalOrderAmount = round((float) $order->total, 2);
            $remainingCod = max(0.00, round($totalOrderAmount - $amount, 2));

            $order->update([
                'advance_amount' => $amount,
                'advance_status' => 'pending',
                'advance_recipient' => 'platform',
                'advance_payment_method' => $method,
                'advance_trx_id' => $trxId,
                'advance_sender_phone' => $senderPhone,
                'remaining_cod' => $remainingCod,
            ]);

            ResellerTransaction::updateOrCreate(
                [
                    'order_id' => $order->id,
                    'type' => 'order_advance',
                ],
                [
                    'reseller_id' => $order->user_id,
                    'amount' => $amount,
                    'trx_type' => 'credit',
                    'source' => $method,
                    'trx_id' => $trxId,
                    'status' => 'pending',
                    'note' => "Advance payment via {$method} for order #{$order->order_number} (Awaiting Admin Verification)",
                ]
            );

            return $order;
        });
    }

    /**
     * Admin Quick-Approve order advance payment.
     */
    public function approveOrderAdvance(Order $order, int $adminId, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $adminId, $note) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $advanceAmount = round((float) $order->advance_amount, 2);
            $totalOrderAmount = round((float) $order->total, 2);
            $remainingCod = max(0.00, round($totalOrderAmount - $advanceAmount, 2));

            $order->update([
                'advance_status' => 'approved',
                'advance_verified_at' => now(),
                'advance_verified_by' => $adminId,
                'advance_admin_note' => $note,
                'remaining_cod' => $remainingCod,
            ]);

            // Update ResellerTransaction to completed
            ResellerTransaction::where('order_id', $order->id)
                ->where('type', 'order_advance')
                ->update([
                    'status' => 'completed',
                    'note' => "Advance payment via {$order->advance_payment_method} verified by Admin for order #{$order->order_number}",
                ]);

            // Post to platform ledger
            if ($advanceAmount > 0) {
                OrderAccountingLedger::create([
                    'order_id' => $order->id,
                    'entry_type' => 'booking',
                    'account_code' => 'cash_platform',
                    'party_type' => 'platform',
                    'party_id' => null,
                    'debit' => $advanceAmount,
                    'credit' => 0.00,
                    'currency' => 'BDT',
                    'reference_id' => $order->advance_trx_id,
                    'description' => "Customer advance verified via {$order->advance_payment_method} for order #{$order->id}",
                ]);
            }

            return $order;
        });
    }

    /**
     * Admin Reject order advance payment.
     */
    public function rejectOrderAdvance(Order $order, int $adminId, string $reason): Order
    {
        return DB::transaction(function () use ($order, $adminId, $reason) {
            $order = Order::lockForUpdate()->findOrFail($order->id);

            $order->update([
                'advance_status' => 'rejected',
                'advance_verified_at' => now(),
                'advance_verified_by' => $adminId,
                'advance_admin_note' => $reason,
                'remaining_cod' => $order->total,
            ]);

            ResellerTransaction::where('order_id', $order->id)
                ->where('type', 'order_advance')
                ->update([
                    'status' => 'rejected',
                    'note' => "Advance payment rejected: {$reason}",
                ]);

            return $order;
        });
    }

    /**
     * Refund order advance to dropshipper wallet if cancelled before shipment.
     */
    public function refundOrderAdvance(Order $order): void
    {
        DB::transaction(function () use ($order) {
            if ($order->advance_status !== 'approved' || (float) $order->advance_amount <= 0) {
                return;
            }

            $alreadyRefunded = ResellerTransaction::where('order_id', $order->id)
                ->where('type', 'advance_refund')
                ->exists();

            if ($alreadyRefunded) {
                return;
            }

            ResellerTransaction::create([
                'reseller_id' => $order->user_id,
                'order_id' => $order->id,
                'amount' => $order->advance_amount,
                'trx_type' => 'credit',
                'type' => 'advance_refund',
                'source' => 'wallet',
                'status' => 'completed',
                'trx_id' => 'REF-ADV-' . $order->id . '-' . time(),
                'note' => "Refund of advance payment for cancelled order #{$order->order_number}",
            ]);
        });
    }

    /**
     * Admin approve wallet topup request.
     */
    public function approveWalletTopup(WalletTopup $topup, int $adminId, ?string $note = null): WalletTopup
    {
        return DB::transaction(function () use ($topup, $adminId, $note) {
            $topup = WalletTopup::lockForUpdate()->findOrFail($topup->id);
            if ($topup->status === 'approved') {
                return $topup;
            }

            $topup->update([
                'status' => 'approved',
                'approved_by' => $adminId,
                'approved_at' => now(),
                'admin_note' => $note,
            ]);

            // Credit reseller wallet balance!
            ResellerTransaction::create([
                'reseller_id' => $topup->user_id,
                'amount' => $topup->amount,
                'trx_type' => 'credit',
                'type' => 'wallet_topup',
                'source' => $topup->payment_method,
                'trx_id' => $topup->trx_id,
                'ref_id' => $topup->id,
                'status' => 'completed',
                'note' => "Wallet balance recharge approved via {$topup->payment_method} (Trx: {$topup->trx_id})",
            ]);

            // Record company cashbook credit (inflow)
            Transaction::updateOrCreate(
                [
                    'ref_id' => $topup->id,
                    'type' => 'wallet_topup',
                ],
                [
                    'amount' => $topup->amount,
                    'trx_id' => $topup->trx_id,
                    'trx_type' => 'credit',
                    'source' => $topup->payment_method,
                    'status' => 'completed',
                    'note' => "Credit transaction for wallet recharge #{$topup->id} via {$topup->payment_method} (Trx: {$topup->trx_id})",
                ]
            );

            return $topup;
        });
    }

    /**
     * Admin reject wallet topup request.
     */
    public function rejectWalletTopup(WalletTopup $topup, int $adminId, string $reason): WalletTopup
    {
        return DB::transaction(function () use ($topup, $adminId, $reason) {
            $topup = WalletTopup::lockForUpdate()->findOrFail($topup->id);
            $topup->update([
                'status' => 'rejected',
                'approved_by' => $adminId,
                'approved_at' => now(),
                'admin_note' => $reason,
            ]);
            return $topup;
        });
    }

    /**
     * Calculate comprehensive financial stake breakdown for an order.
     */
    public function calculateOrderStakeBreakdown(Order $order): array
    {
        $order->loadMissing([
            'items.shop',
            'items.product.vendor',
            'deliveryInformation',
            'user'
        ]);

        $orderTotal = round((float) ($order->total ?? 0), 2);
        $customerShippingCharge = round((float) ($order->shipping_fee ?? 0), 2);
        $advanceAmount = round((float) ($order->advance_amount ?? 0), 2);
        $advanceStatus = $order->advance_status ?: 'none';
        $advanceRecipient = $order->advance_recipient ?: 'platform';

        // Delivery info resolution (CarryBee)
        $deliveryInfo = $order->deliveryInformation;
        $consignmentId = $order->consignment_id ?: $deliveryInfo?->consignment_id;

        // Courier collectable
        if ($order->remaining_cod !== null) {
            $courierCollectable = round((float) $order->remaining_cod, 2);
        } elseif ($deliveryInfo?->collectable_amount !== null) {
            $courierCollectable = round((float) $deliveryInfo->collectable_amount, 2);
        } else {
            $courierCollectable = $orderTotal;
        }

        // Courier Delivery Fee and COD Fee resolution
        // Highest priority: CarryBee Delivery Information from Consignment ID
        if ($deliveryInfo?->delivery_fee !== null && (float) $deliveryInfo->delivery_fee > 0) {
            $courierDeliveryFee = round((float) $deliveryInfo->delivery_fee, 2);
        } elseif ($order->courier_cost_confirmed && $order->actual_courier_fee !== null) {
            $courierDeliveryFee = round((float) $order->actual_courier_fee, 2);
        } elseif ($order->actual_courier_fee !== null) {
            $courierDeliveryFee = round((float) $order->actual_courier_fee, 2);
        } else {
            $courierDeliveryFee = $customerShippingCharge;
        }

        if ($deliveryInfo?->total_fee !== null && (float) $deliveryInfo->total_fee > $courierDeliveryFee) {
            $courierCodFee = round((float) $deliveryInfo->total_fee - $courierDeliveryFee, 2);
        } elseif ($order->courier_cost_confirmed && $order->actual_cod_fee !== null) {
            $courierCodFee = round((float) $order->actual_cod_fee, 2);
        } elseif ($order->actual_cod_fee !== null) {
            $courierCodFee = round((float) $order->actual_cod_fee, 2);
        } else {
            // Default 1% COD fee on collectable
            $courierCodFee = $courierCollectable > 0 ? round($courierCollectable * 0.01, 2) : 0.00;
        }

        $totalCourierCost = round($courierDeliveryFee + $courierCodFee, 2);
        $courierNetToSupplier = round($courierCollectable - $totalCourierCost, 2);

        // Identify primary shop / vendor
        $primaryItem = $order->items->first();
        $vendor = $primaryItem?->shop ?: $primaryItem?->product?->vendor;
        $hasOwnDeliveryAccount = (bool) ($vendor?->has_own_delivery_account || $order->courier_account_owner === 'vendor');

        // Entitlements
        $supplierVendorPrice = 0.00;
        $adminBasePrice = 0.00;
        $resellerSellingPrice = 0.00;

        foreach ($order->items as $item) {
            $qty = (int) ($item->qty ?? $item->quantity ?? 1);
            $vPrice = round((float) ($item->unit_price ?? $item->product?->unit_price ?? 0), 2);
            $aPrice = round((float) ($item->admin_price ?? $item->product?->admin_price ?? $vPrice), 2);
            $sPrice = round((float) ($item->reseller_price ?? $item->price ?? $aPrice), 2);

            $supplierVendorPrice += round($vPrice * $qty, 2);
            $adminBasePrice += round($aPrice * $qty, 2);
            $resellerSellingPrice += round($sPrice * $qty, 2);
        }

        $supplierVendorPrice = round($supplierVendorPrice, 2);
        $adminBasePrice = round($adminBasePrice, 2);
        $resellerSellingPrice = round($resellerSellingPrice, 2);

        // Platform settlement breakdown
        // When vendor has own delivery account: courier delivers all funds to vendor.
        // Vendor owes Platform = Courier Net to Supplier - Supplier Vendor Price
        if ($hasOwnDeliveryAccount) {
            if ($courierCollectable <= 0 && $supplierVendorPrice <= 0) {
                $supplierOwesPlatform = 0.00;
                $platformOwesSupplier = 0.00;
            } else {
                $supplierOwesPlatform = round($courierNetToSupplier - $supplierVendorPrice, 2);
                $platformOwesSupplier = 0.00;
            }
        } else {
            $supplierOwesPlatform = 0.00;
            $platformOwesSupplier = $supplierVendorPrice;
        }

        // Dropshipper profit calculation:
        // Formula: Customer collected - Admin Base Price - Customer Delivery Charge - Courier COD Fee
        $customerCollected = $courierCollectable + ($advanceStatus === 'approved' ? $advanceAmount : 0.00);
        if ($courierCollectable <= 0 && ($advanceStatus !== 'approved' || $advanceAmount <= 0)) {
            $dropshipperProfit = 0.00;
        } else {
            $dropshipperProfit = max(0.00, round($customerCollected - $adminBasePrice - $customerShippingCharge - $courierCodFee, 2));
        }

        // Platform Net Profit calculation:
        $platformProductCommission = round($adminBasePrice - $supplierVendorPrice, 2);
        $platformDeliveryMargin = round($customerShippingCharge - $courierDeliveryFee, 2);
        $platformNetProfit = round($platformProductCommission + $platformDeliveryMargin, 2);

        // Balance check: Supplier Owes Platform == Dropshipper Profit + Platform Net Profit (for has_own_delivery_account)
        $isBalanced = ($courierCollectable <= 0)
            ? true
            : ($hasOwnDeliveryAccount
                ? (abs($supplierOwesPlatform - ($dropshipperProfit + $platformNetProfit)) < 0.05)
                : true);

        // Existing settlements check
        $settlements = OrderSettlement::where('order_id', $order->id)->get();
        $supplierSettlement = $settlements->firstWhere('settlement_type', OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE);
        $resellerSettlement = $settlements->firstWhere('settlement_type', OrderSettlement::TYPE_RESELLER_PROFIT);

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'vendor_id' => $vendor?->id,
            'vendor_name' => $vendor?->shop_name ?: $vendor?->owner_name,
            'vendor_phone' => $vendor?->emergency_contact ?: $vendor?->whatsapp,
            'has_own_delivery_account' => $hasOwnDeliveryAccount,
            'courier_account_owner' => $hasOwnDeliveryAccount ? 'vendor' : 'platform',
            'consignment_id' => $consignmentId,
            'courier' => [
                'collectable_amount' => $courierCollectable,
                'delivery_fee' => $courierDeliveryFee,
                'cod_fee' => $courierCodFee,
                'total_courier_fee' => $totalCourierCost,
                'fee_settlement_status' => 'settled_at_source',
                'fee_settlement_note' => 'Delivery fee and COD fee automatically deducted and settled at source by delivery company prior to disbursement',
                'net_received_by_supplier' => $courierNetToSupplier,
                'net_disbursed' => $courierNetToSupplier,
            ],
            'supplier' => [
                'vendor_price' => $supplierVendorPrice,
                'supplier_owes_platform' => $supplierOwesPlatform,
                'platform_owes_supplier' => $platformOwesSupplier,
                'is_settled' => $supplierSettlement?->status === OrderSettlement::STATUS_SETTLED,
                'settled_trx_id' => $supplierSettlement?->settled_trx_id,
                'settled_at' => $supplierSettlement?->settled_at?->toIso8601String(),
            ],
            'dropshipper' => [
                'user_id' => $order->user_id,
                'user_name' => $order->user?->name,
                'user_phone' => $order->user?->phone,
                'customer_collected' => $customerCollected,
                'admin_base_price' => $adminBasePrice,
                'customer_delivery_charge' => $customerShippingCharge,
                'cod_charge' => $courierCodFee,
                'profit' => $dropshipperProfit,
                'is_settled' => $resellerSettlement?->status === OrderSettlement::STATUS_SETTLED,
                'settled_trx_id' => $resellerSettlement?->settled_trx_id,
                'settled_at' => $resellerSettlement?->settled_at?->toIso8601String(),
            ],
            'platform' => [
                'product_commission' => $platformProductCommission,
                'delivery_margin' => $platformDeliveryMargin,
                'net_profit' => $platformNetProfit,
            ],
            'advance' => [
                'amount' => $advanceAmount,
                'status' => $advanceStatus,
                'recipient' => $advanceRecipient,
                'payment_method' => $order->advance_payment_method,
                'trx_id' => $order->advance_trx_id,
                'sender_phone' => $order->advance_sender_phone,
            ],
            'balance' => [
                'is_balanced' => $isBalanced,
                'sum_difference' => ($courierCollectable <= 0) ? 0.00 : round($supplierOwesPlatform - ($dropshipperProfit + $platformNetProfit), 2),
            ],
            'is_editable' => !($supplierSettlement?->status === OrderSettlement::STATUS_SETTLED && $resellerSettlement?->status === OrderSettlement::STATUS_SETTLED),
        ];
    }

    /**
     * Save admin breakdown adjustments prior to settlement.
     */
    public function saveBreakdownAdjustments(Order $order, array $adjusted, ?int $adminUserId = null): array
    {
        return DB::transaction(function () use ($order, $adjusted, $adminUserId) {
            $lockedOrder = Order::lockForUpdate()->findOrFail($order->id);

            // Update order courier fee attributes if provided
            $updates = [];
            if (array_key_exists('courier_delivery_fee', $adjusted)) {
                $updates['actual_courier_fee'] = round((float) $adjusted['courier_delivery_fee'], 2);
            }
            if (array_key_exists('courier_cod_fee', $adjusted)) {
                $updates['actual_cod_fee'] = round((float) $adjusted['courier_cod_fee'], 2);
            }
            if (isset($updates['actual_courier_fee']) || isset($updates['actual_cod_fee'])) {
                $delFee = $updates['actual_courier_fee'] ?? (float) ($lockedOrder->actual_courier_fee ?? 0);
                $codFee = $updates['actual_cod_fee'] ?? (float) ($lockedOrder->actual_cod_fee ?? 0);
                $updates['actual_courier_cost'] = round($delFee + $codFee, 2);
                $updates['courier_cost_confirmed'] = true;
            }
            if (array_key_exists('remaining_cod', $adjusted)) {
                $updates['remaining_cod'] = round((float) $adjusted['remaining_cod'], 2);
            }
            if (array_key_exists('collectable_amount', $adjusted)) {
                $updates['remaining_cod'] = round((float) $adjusted['collectable_amount'], 2);
            }

            if (!empty($updates)) {
                $lockedOrder->update($updates);
            }

            // Also update DeliveryAssignedInfo if exists
            $deliveryInfo = $lockedOrder->deliveryInformation;
            if ($deliveryInfo) {
                $dUpdates = [];
                if (array_key_exists('collectable_amount', $adjusted)) {
                    $dUpdates['collectable_amount'] = round((float) $adjusted['collectable_amount'], 2);
                }
                if (array_key_exists('courier_delivery_fee', $adjusted)) {
                    $dUpdates['delivery_fee'] = round((float) $adjusted['courier_delivery_fee'], 2);
                }
                if (isset($dUpdates['delivery_fee']) || array_key_exists('courier_cod_fee', $adjusted) || (isset($dUpdates['collectable_amount']) && $dUpdates['collectable_amount'] <= 0)) {
                    $delFee = $dUpdates['delivery_fee'] ?? (float) ($deliveryInfo->delivery_fee ?? 0);
                    if (array_key_exists('courier_cod_fee', $adjusted)) {
                        $codFee = (float) $adjusted['courier_cod_fee'];
                    } elseif (isset($dUpdates['collectable_amount']) && $dUpdates['collectable_amount'] <= 0) {
                        $codFee = 0.0;
                    } else {
                        $codFee = max(0, (float) (($deliveryInfo->total_fee ?? 0) - $delFee));
                    }
                    $dUpdates['total_fee'] = round($delFee + $codFee, 2);
                }
                if (!empty($dUpdates)) {
                    $deliveryInfo->update($dUpdates);
                }
            }

            // If item prices were adjusted via items array
            if (!empty($adjusted['items']) && is_array($adjusted['items'])) {
                foreach ($adjusted['items'] as $itemData) {
                    if (!empty($itemData['id'])) {
                        $item = OrderItem::where('id', $itemData['id'])->where('order_id', $lockedOrder->id)->first();
                        if ($item) {
                            $itemUpdates = [];
                            if (isset($itemData['unit_price'])) $itemUpdates['unit_price'] = (float) $itemData['unit_price'];
                            if (isset($itemData['admin_price'])) $itemUpdates['admin_price'] = (float) $itemData['admin_price'];
                            if (isset($itemData['reseller_price'])) $itemUpdates['reseller_price'] = (float) $itemData['reseller_price'];
                            if (!empty($itemUpdates)) $item->update($itemUpdates);
                        }
                    }
                }
            } elseif (array_key_exists('supplier_vendor_price', $adjusted) || array_key_exists('admin_base_price', $adjusted) || array_key_exists('reseller_price', $adjusted)) {
                // If top-level stake prices are sent directly from the breakdown modal
                $firstItem = $lockedOrder->items()->first();
                if ($firstItem) {
                    $qty = (int) ($firstItem->qty ?? 1);
                    if ($qty <= 0) $qty = 1;
                    $itemUpdates = [];
                    if (array_key_exists('supplier_vendor_price', $adjusted)) {
                        $itemUpdates['unit_price'] = round((float) $adjusted['supplier_vendor_price'] / $qty, 2);
                    }
                    if (array_key_exists('admin_base_price', $adjusted)) {
                        $itemUpdates['admin_price'] = round((float) $adjusted['admin_base_price'] / $qty, 2);
                    }
                    if (array_key_exists('reseller_price', $adjusted)) {
                        $itemUpdates['reseller_price'] = round((float) $adjusted['reseller_price'] / $qty, 2);
                    }
                    if (!empty($itemUpdates)) {
                        $firstItem->update($itemUpdates);
                    }
                }
            }

            // Reload relationships
            $lockedOrder->load(['items', 'deliveryInformation']);

            // Recalculate fresh breakdown
            $breakdown = $this->calculateOrderStakeBreakdown($lockedOrder);

            // Update OrderSettlement rows
            $supplierSettlement = OrderSettlement::where('order_id', $lockedOrder->id)
                ->where('settlement_type', OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE)
                ->first();
            if ($supplierSettlement && $supplierSettlement->status !== OrderSettlement::STATUS_SETTLED) {
                $vendorPrice = $breakdown['supplier']['vendor_price'];
                $courierNet = $breakdown['courier']['net_received_by_supplier'];
                $supplierOwes = $breakdown['supplier']['supplier_owes_platform'];

                if (isset($adjusted['collectable_amount']) && (float)$adjusted['collectable_amount'] <= 0 && $vendorPrice <= 0) {
                    $supplierOwes = 0.00;
                }

                $adminNote = !empty($adjusted['admin_note'])
                    ? $adjusted['admin_note']
                    : "Supplier settlement adjusted by admin #{$adminUserId}. Supplier owes platform: ৳{$supplierOwes}";

                $supplierSettlement->update([
                    'gross_entitlement' => $vendorPrice,
                    'deducted_by_courier' => $courierNet,
                    'settleable_amount' => $supplierOwes,
                    'net_payable_amount' => $supplierOwes,
                    'admin_note' => $adminNote,
                ]);
            }

            $resellerSettlement = OrderSettlement::where('order_id', $lockedOrder->id)
                ->where('settlement_type', OrderSettlement::TYPE_RESELLER_PROFIT)
                ->first();
            if ($resellerSettlement && $resellerSettlement->status !== OrderSettlement::STATUS_SETTLED) {
                $dropshipperProfit = array_key_exists('dropshipper_profit', $adjusted)
                    ? round((float) $adjusted['dropshipper_profit'], 2)
                    : $breakdown['dropshipper']['profit'];

                $adminNote = !empty($adjusted['admin_note'])
                    ? $adjusted['admin_note']
                    : "Dropshipper profit adjusted by admin #{$adminUserId}: ৳{$dropshipperProfit}";

                $resellerSettlement->update([
                    'gross_entitlement' => $dropshipperProfit,
                    'settleable_amount' => $dropshipperProfit,
                    'net_payable_amount' => $dropshipperProfit,
                    'admin_note' => $adminNote,
                ]);
            }

            return $breakdown;
        });
    }

    /**
     * Create multi-order vendor settlement transaction invoice.
     */
    public function createVendorSettlementInvoice(array $data, ?int $adminUserId = null): SettlementInvoice
    {
        return DB::transaction(function () use ($data, $adminUserId) {
            $vendorId = (int) $data['vendor_id'];
            $orderIds = array_map('intval', $data['order_ids'] ?? []);
            if (empty($orderIds)) {
                throw new \InvalidArgumentException("No order IDs provided for settlement invoice.");
            }

            $orders = Order::whereIn('id', $orderIds)->get();
            $invoiceNumber = $data['invoice_number'] ?? ('INV-V' . $vendorId . '-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4)));
            $paymentMethod = $data['payment_method'] ?? 'bank';
            $trxId = $data['trx_id'] ?? null;
            $note = $data['note'] ?? null;
            $status = $data['status'] ?? SettlementInvoice::STATUS_PENDING;
            $adminPaymentDetails = $data['admin_payment_details'] ?? null;

            $totalCollectable = 0.00;
            $totalDeliveryFee = 0.00;
            $totalCodFee = 0.00;
            $totalCourierNet = 0.00;
            $totalSupplierPrice = 0.00;
            $totalAmountOwed = 0.00;
            $totalDropshipperPayout = 0.00;
            $totalPlatformNetProfit = 0.00;

            $breakdownList = [];

            $isDirectlyPaid = in_array($status, [SettlementInvoice::STATUS_PAID, SettlementInvoice::STATUS_APPROVED, 'settled']);

            foreach ($orders as $order) {
                $b = $this->calculateOrderStakeBreakdown($order);
                $breakdownList[] = $b;

                $totalCollectable += $b['courier']['collectable_amount'];
                $totalDeliveryFee += $b['courier']['delivery_fee'];
                $totalCodFee += $b['courier']['cod_fee'];
                $totalCourierNet += $b['courier']['net_received_by_supplier'];
                $totalSupplierPrice += $b['supplier']['vendor_price'];
                $totalAmountOwed += $b['supplier']['supplier_owes_platform'];
                $totalDropshipperPayout += $b['dropshipper']['profit'];
                $totalPlatformNetProfit += $b['platform']['net_profit'];

                // Update or create order settlement for supplier
                OrderSettlement::updateOrCreate(
                    [
                        'order_id' => $order->id,
                        'settlement_type' => OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE,
                    ],
                    [
                        'vendor_id' => $vendorId,
                        'user_type' => 'vendor',
                        'status' => $isDirectlyPaid ? OrderSettlement::STATUS_SETTLED : OrderSettlement::STATUS_PENDING,
                        'settleable_amount' => $b['supplier']['supplier_owes_platform'],
                        'gross_entitlement' => $b['supplier']['vendor_price'],
                        'deducted_by_courier' => $b['courier']['net_received_by_supplier'],
                        'settled_trx_id' => $isDirectlyPaid ? ($trxId ?: $invoiceNumber) : null,
                        'settled_at' => $isDirectlyPaid ? now() : null,
                        'admin_note' => $isDirectlyPaid
                            ? "Settled via Vendor Invoice {$invoiceNumber}. Trx ID: {$trxId}"
                            : "Invoice {$invoiceNumber} issued (Pending Payment). Owed: ৳{$b['supplier']['supplier_owes_platform']}",
                    ]
                );
            }

            $courierSettledTotal = round($totalDeliveryFee + $totalCodFee, 2);
            $settlementAuditNote = "CarryBee delivery fee (৳{$totalDeliveryFee}) & COD fee (৳{$totalCodFee}) totaling ৳{$courierSettledTotal} were automatically deducted and settled at source by the delivery company. Net disbursed: ৳{$totalCourierNet}.";
            $finalNote = $note ? ($note . " | " . $settlementAuditNote) : $settlementAuditNote;

            // Create SettlementInvoice record
            $invoice = SettlementInvoice::create([
                'invoice_number' => $invoiceNumber,
                'vendor_id' => $vendorId,
                'user_id' => $adminUserId,
                'type' => 'vendor_settlement',
                'orders_count' => count($orders),
                'total_collectable' => round($totalCollectable, 2),
                'total_delivery_fee' => round($totalDeliveryFee, 2),
                'total_cod_fee' => round($totalCodFee, 2),
                'total_courier_net' => round($totalCourierNet, 2),
                'total_supplier_price' => round($totalSupplierPrice, 2),
                'total_amount_owed' => round($totalAmountOwed, 2),
                'total_dropshipper_payout' => round($totalDropshipperPayout, 2),
                'total_platform_net_profit' => round($totalPlatformNetProfit, 2),
                'payment_method' => $paymentMethod,
                'trx_id' => $trxId,
                'status' => $status,
                'order_ids' => $orderIds,
                'orders_breakdown' => $breakdownList,
                'admin_payment_details' => $adminPaymentDetails,
                'note' => $finalNote,
                'settled_at' => $isDirectlyPaid ? now() : null,
                'paid_at' => $isDirectlyPaid ? now() : null,
                'approved_at' => $isDirectlyPaid ? now() : null,
                'approved_by' => $isDirectlyPaid ? $adminUserId : null,
            ]);

            // Record Company cash/bank transaction if paid directly
            if ($isDirectlyPaid && $totalAmountOwed > 0) {
                Transaction::create([
                    'ref_id' => $invoice->id,
                    'type' => 'vendor_settlement_collection',
                    'amount' => round($totalAmountOwed, 2),
                    'trx_id' => $trxId ?: $invoiceNumber,
                    'trx_type' => 'credit',
                    'source' => $paymentMethod,
                    'status' => 'completed',
                    'note' => "Collected owed settlement of ৳{$totalAmountOwed} from Vendor #{$vendorId} via Invoice {$invoiceNumber} (Trx: {$trxId})",
                ]);
            }

            return $invoice;
        });
    }

    /**
     * Vendor submits payment for an invoice with payment_method, trx_id, and sender account.
     */
    public function submitVendorInvoicePayment(int $invoiceId, array $data, int $userId): SettlementInvoice
    {
        return DB::transaction(function () use ($invoiceId, $data, $userId) {
            $invoice = SettlementInvoice::lockForUpdate()->findOrFail($invoiceId);

            if ($invoice->status === SettlementInvoice::STATUS_PAID || $invoice->status === SettlementInvoice::STATUS_APPROVED) {
                throw new \InvalidArgumentException("This invoice is already paid and settled.");
            }

            $paymentMethod = $data['payment_method'] ?? $invoice->payment_method ?? 'bkash';
            $trxId = $data['trx_id'] ?? null;
            if (empty($trxId)) {
                throw new \InvalidArgumentException("Transaction ID (Trx ID) is required.");
            }
            $senderAccount = $data['sender_account'] ?? null;
            $paidAmount = isset($data['paid_amount']) ? round((float) $data['paid_amount'], 2) : $invoice->total_amount_owed;
            $vendorNote = $data['note'] ?? null;

            $updatedNote = $invoice->note;
            if ($vendorNote) {
                $updatedNote .= " | Vendor Note: {$vendorNote}";
            }

            $invoice->update([
                'status' => SettlementInvoice::STATUS_PAID,
                'payment_method' => $paymentMethod,
                'trx_id' => $trxId,
                'sender_account' => $senderAccount,
                'paid_amount' => $paidAmount,
                'paid_by' => $userId,
                'paid_at' => now(),
                'settled_at' => now(),
                'note' => $updatedNote,
            ]);

            // Mark order settlements as settled
            if (!empty($invoice->order_ids)) {
                foreach ($invoice->order_ids as $orderId) {
                    OrderSettlement::updateOrCreate(
                        [
                            'order_id' => $orderId,
                            'settlement_type' => OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE,
                        ],
                        [
                            'vendor_id' => $invoice->vendor_id,
                            'user_type' => 'vendor',
                            'status' => OrderSettlement::STATUS_SETTLED,
                            'settled_trx_id' => $trxId,
                            'settled_at' => now(),
                            'admin_note' => "Settled via Vendor Invoice {$invoice->invoice_number}. Trx: {$trxId}",
                        ]
                    );
                }
            }

            // Record transaction credit
            if ($paidAmount > 0) {
                Transaction::create([
                    'ref_id' => $invoice->id,
                    'type' => 'vendor_settlement_collection',
                    'amount' => $paidAmount,
                    'trx_id' => $trxId,
                    'trx_type' => 'credit',
                    'source' => $paymentMethod,
                    'status' => 'completed',
                    'note' => "Collected owed settlement of ৳{$paidAmount} from Vendor #{$invoice->vendor_id} via Invoice {$invoice->invoice_number} (Trx: {$trxId})",
                ]);
            }

            return $invoice;
        });
    }

    /**
     * Admin approves a vendor invoice.
     */
    public function approveVendorInvoice(int $invoiceId, ?string $adminTrxId = null, ?int $adminUserId = null): SettlementInvoice
    {
        return DB::transaction(function () use ($invoiceId, $adminTrxId, $adminUserId) {
            $invoice = SettlementInvoice::lockForUpdate()->findOrFail($invoiceId);

            $trxId = $adminTrxId ?: ($invoice->trx_id ?: $invoice->invoice_number);

            $invoice->update([
                'status' => SettlementInvoice::STATUS_APPROVED,
                'trx_id' => $trxId,
                'approved_by' => $adminUserId,
                'approved_at' => now(),
                'settled_at' => now(),
            ]);

            if (!empty($invoice->order_ids)) {
                foreach ($invoice->order_ids as $orderId) {
                    OrderSettlement::updateOrCreate(
                        [
                            'order_id' => $orderId,
                            'settlement_type' => OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE,
                        ],
                        [
                            'vendor_id' => $invoice->vendor_id,
                            'user_type' => 'vendor',
                            'status' => OrderSettlement::STATUS_SETTLED,
                            'settled_trx_id' => $trxId,
                            'settled_at' => now(),
                            'admin_note' => "Approved via Vendor Invoice {$invoice->invoice_number}. Trx: {$trxId}",
                        ]
                    );
                }
            }

            return $invoice;
        });
    }

    /**
     * Reject a vendor invoice.
     */
    public function rejectVendorInvoice(int $invoiceId, string $reason, ?int $adminUserId = null): SettlementInvoice
    {
        return DB::transaction(function () use ($invoiceId, $reason, $adminUserId) {
            $invoice = SettlementInvoice::lockForUpdate()->findOrFail($invoiceId);

            $invoice->update([
                'status' => SettlementInvoice::STATUS_REJECTED,
                'rejected_by' => $adminUserId,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            return $invoice;
        });
    }

    /**
     * Get Admin Payment Accounts (Bank, bKash, Nagad) to show on invoices.
     */
    public function getAdminPaymentAccounts(): array
    {
        $adminUserIds = User::where('role', 'admin')->orWhere('user_type', 'admin')->pluck('id');
        $bankAccounts = UserBankAccount::whereIn('user_id', $adminUserIds)
            ->where('is_active', 1)
            ->get();

        $accounts = [];
        foreach ($bankAccounts as $acc) {
            $accounts[] = [
                'id' => $acc->id,
                'type' => $acc->type,
                'provider' => $acc->bank_name,
                'account_number' => $acc->account_no,
                'account_name' => $acc->acc_name,
                'branch' => $acc->branch,
                'routing' => $acc->route,
            ];
        }

        if (empty($accounts)) {
            $accounts = [
                [
                    'id' => 1,
                    'type' => 'MFS',
                    'provider' => 'bKash (Merchant / Personal)',
                    'account_number' => '01782084390',
                    'account_name' => 'ResellerBrain Admin (Mir Fahim)',
                    'branch' => null,
                    'routing' => null,
                ],
                [
                    'id' => 2,
                    'type' => 'MFS',
                    'provider' => 'Nagad (Personal)',
                    'account_number' => '01782084390',
                    'account_name' => 'ResellerBrain Admin (Mir Fahim)',
                    'branch' => null,
                    'routing' => null,
                ],
                [
                    'id' => 3,
                    'type' => 'Bank',
                    'provider' => 'UCB (United Commercial Bank)',
                    'account_number' => '4534563001',
                    'account_name' => 'ResellerBrain Technologies Ltd.',
                    'branch' => 'Bongshal Branch, Dhaka',
                    'routing' => '5636501',
                ],
            ];
        }

        return $accounts;
    }

    /**
     * Admin direct disbursement to dropshipper without withdraw request.
     */
    public function disburseDropshipperProfitDirectly(Order $order, array $data, ?int $adminUserId = null): array
    {
        return DB::transaction(function () use ($order, $data, $adminUserId) {
            $lockedOrder = Order::lockForUpdate()->findOrFail($order->id);
            $breakdown = $this->calculateOrderStakeBreakdown($lockedOrder);

            $payoutAmount = isset($data['amount']) ? round((float) $data['amount'], 2) : $breakdown['dropshipper']['profit'];
            $paymentMethod = $data['payment_method'] ?? 'wallet'; // 'wallet', 'bank', 'bkash', 'nagad', 'cash'
            $trxId = $data['trx_id'] ?? ('DIR-DISB-' . $lockedOrder->id . '-' . time());
            $note = $data['note'] ?? "Direct disbursement by Admin #{$adminUserId} for order #{$lockedOrder->order_number}";

            // Find or create reseller settlement
            $resellerSettlement = OrderSettlement::firstOrCreate(
                [
                    'order_id' => $lockedOrder->id,
                    'settlement_type' => OrderSettlement::TYPE_RESELLER_PROFIT,
                ],
                [
                    'payable_user_id' => $lockedOrder->user_id,
                    'user_type' => 'dropshipper',
                    'gross_entitlement' => $payoutAmount,
                    'settleable_amount' => $payoutAmount,
                    'net_payable_amount' => $payoutAmount,
                    'currency' => 'BDT',
                    'status' => OrderSettlement::STATUS_PENDING,
                    'trx_id' => 'SETTLE-R-' . $lockedOrder->id . '-' . time(),
                ]
            );

            // Mark settlement settled
            $resellerSettlement->update([
                'settleable_amount' => $payoutAmount,
                'net_payable_amount' => $payoutAmount,
                'status' => OrderSettlement::STATUS_SETTLED,
                'settled_trx_id' => $trxId,
                'settled_at' => now(),
                'admin_note' => $note,
            ]);

            if ($paymentMethod === 'wallet') {
                // Credit to reseller wallet balance
                ResellerTransaction::create([
                    'reseller_id' => $lockedOrder->user_id,
                    'amount' => $payoutAmount,
                    'trx_id' => $trxId,
                    'trx_type' => 'credit',
                    'type' => 'order_settlement',
                    'source' => 'wallet',
                    'order_id' => $lockedOrder->id,
                    'ref_id' => $resellerSettlement->id,
                    'status' => 'completed',
                    'note' => "Dropshipper profit credited to wallet for order #{$lockedOrder->order_number} (Trx: {$trxId})",
                ]);
            } else {
                // Direct bank/MFS payout: create credit (earning) then direct transferred debit (payout)
                ResellerTransaction::create([
                    'reseller_id' => $lockedOrder->user_id,
                    'amount' => $payoutAmount,
                    'trx_id' => 'EARN-' . $trxId,
                    'trx_type' => 'credit',
                    'type' => 'order_settlement',
                    'source' => 'order',
                    'order_id' => $lockedOrder->id,
                    'ref_id' => $resellerSettlement->id,
                    'status' => 'completed',
                    'note' => "Dropshipper profit entitlement for order #{$lockedOrder->order_number}",
                ]);

                // Create transferred withdraw request
                $withdraw = WithdrawRequest::create([
                    'user_id' => $lockedOrder->user_id,
                    'amount' => $payoutAmount,
                    'reserved_amount' => 0.00,
                    'status' => 'transferred',
                    'payment_method' => $paymentMethod,
                    'bank_reference' => $trxId,
                    'transferred_at' => now(),
                    'note' => "Direct disbursement by Admin: {$note}",
                    'type' => 'direct_disbursement',
                ]);

                // Record debit in reseller transactions
                ResellerTransaction::create([
                    'reseller_id' => $lockedOrder->user_id,
                    'amount' => $payoutAmount,
                    'trx_id' => $trxId,
                    'trx_type' => 'debit',
                    'type' => 'withdraw',
                    'source' => $paymentMethod,
                    'order_id' => $lockedOrder->id,
                    'ref_id' => $withdraw->id,
                    'status' => 'completed',
                    'note' => "Direct payout transferred via {$paymentMethod} (Ref: {$trxId})",
                ]);

                // Record company cashbook debit
                Transaction::create([
                    'ref_id' => $withdraw->id,
                    'type' => 'reseller_withdraw',
                    'amount' => $payoutAmount,
                    'trx_id' => $trxId,
                    'trx_type' => 'debit',
                    'source' => $paymentMethod,
                    'status' => 'completed',
                    'note' => "Direct payout for dropshipper profit on order #{$lockedOrder->order_number} via {$paymentMethod} (Ref: {$trxId})",
                ]);
            }

            return [
                'success' => true,
                'message' => "Dropshipper profit of ৳{$payoutAmount} disbursed successfully via {$paymentMethod}.",
                'settlement' => $resellerSettlement,
                'breakdown' => $this->calculateOrderStakeBreakdown($lockedOrder),
            ];
        });
    }
}
