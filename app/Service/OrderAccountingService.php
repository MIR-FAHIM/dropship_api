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
}
