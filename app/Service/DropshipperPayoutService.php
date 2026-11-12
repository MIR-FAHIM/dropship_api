<?php

namespace App\Service;

use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\OrderStatus;
use App\Models\ResellerTransaction;
use App\Models\SettlementInvoice;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawRequest;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Dropshipper payout invoices: one invoice pays a dropshipper's profit for many
 * orders at once (settlement_invoices.type = dropshipper_payout).
 *
 *  pending  -> issued, not paid yet (its orders are reserved for it)
 *  paid     -> money sent; every order's profit settlement is settled
 *  cancelled-> an unpaid invoice withdrawn; its orders are free again
 *
 * Paying an order here is the same accounting as the single-order "direct profit
 * payout" (OrderAccountingService::disburseDropshipperProfitDirectly), but with
 * one transfer record for the whole invoice instead of one per order.
 */
class DropshipperPayoutService
{
    public const PAYMENT_METHODS = ['wallet', 'bkash', 'nagad', 'rocket', 'bank_transfer', 'cash'];

    /** Statuses where the dropshipper's profit can never be paid. */
    private const DEAD_STATUSES = [7, 8, 11, 12];

    public function __construct(
        private readonly OrderAccountingService $accounting,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * Orders of a dropshipper whose profit is not paid yet, each marked payable or
     * not (with the reason).
     *
     * @return array{rows: array<int, array>, payable_count: int, payable_total: float}
     */
    public function payableOrders(int $dropshipperId, ?int $ignoreInvoiceId = null): array
    {
        $settledOrderIds = OrderSettlement::where('settlement_type', OrderSettlement::TYPE_RESELLER_PROFIT)
            ->where('status', OrderSettlement::STATUS_SETTLED)
            ->whereIn('order_id', Order::where('user_id', $dropshipperId)->select('id'))
            ->pluck('order_id')
            ->all();

        $orders = Order::with(['deliveryInformation'])
            ->where('user_id', $dropshipperId)
            ->whereIn('status', ['6', '9', '10', '13'])
            ->whereNotIn('id', $settledOrderIds)
            ->orderBy('id')
            ->limit(300)
            ->get();

        $reserved = $this->reservedOrderIds($dropshipperId, $ignoreInvoiceId);
        $statusNames = OrderStatus::pluck('name', 'id');

        $rows = [];
        foreach ($orders as $order) {
            $rows[] = $this->describeOrder($order, $reserved, $statusNames);
        }

        $payable = array_filter($rows, fn ($r) => $r['payable']);

        return [
            'rows' => $rows,
            'payable_count' => count($payable),
            'payable_total' => round(array_sum(array_column($payable, 'profit')), 2),
        ];
    }

    /**
     * Create a payout invoice. status "paid" pays it immediately; "pending" only issues it.
     */
    public function create(int $dropshipperId, array $orderIds, array $data, ?int $adminId): SettlementInvoice
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if ($orderIds === []) {
            throw new InvalidArgumentException('Select at least one order.');
        }

        $status = ($data['status'] ?? SettlementInvoice::STATUS_PAID) === SettlementInvoice::STATUS_PENDING
            ? SettlementInvoice::STATUS_PENDING
            : SettlementInvoice::STATUS_PAID;

        return DB::transaction(function () use ($dropshipperId, $orderIds, $data, $adminId, $status) {
            $dropshipper = User::lockForUpdate()->find($dropshipperId);
            if (!$dropshipper) {
                throw new InvalidArgumentException('Dropshipper not found.');
            }

            $orders = Order::with('deliveryInformation')->whereIn('id', $orderIds)->lockForUpdate()->get()->keyBy('id');
            $reserved = $this->reservedOrderIds($dropshipperId, null);
            $statusNames = OrderStatus::pluck('name', 'id');

            $rows = [];
            foreach ($orderIds as $orderId) {
                $order = $orders->get($orderId);
                if (!$order || (int) $order->user_id !== $dropshipperId) {
                    throw new InvalidArgumentException("Order #{$orderId} does not belong to this dropshipper.");
                }
                $row = $this->describeOrder($order, $reserved, $statusNames);
                if (!$row['payable']) {
                    throw new InvalidArgumentException("Order {$row['order_number']} can't be paid: {$row['reason']}.");
                }
                $rows[] = $row;
            }

            $total = round(array_sum(array_column($rows, 'profit')), 2);
            $invoice = SettlementInvoice::create([
                'invoice_number' => $this->newInvoiceNumber($dropshipperId),
                'dropshipper_id' => $dropshipperId,
                'user_id' => $adminId,
                'type' => SettlementInvoice::TYPE_DROPSHIPPER_PAYOUT,
                'orders_count' => count($rows),
                'total_collectable' => round(array_sum(array_column($rows, 'collected')), 2),
                'total_cod_fee' => round(array_sum(array_column($rows, 'cod_fee')), 2),
                'total_dropshipper_payout' => $total,
                'payment_method' => $data['payment_method'] ?? 'wallet',
                'status' => SettlementInvoice::STATUS_PENDING,
                'order_ids' => array_column($rows, 'order_id'),
                'orders_breakdown' => $rows,
                'note' => $data['note'] ?? null,
            ]);

            if ($status === SettlementInvoice::STATUS_PAID) {
                $invoice = $this->applyPayment($invoice, $data, $adminId);
            } else {
                $this->notifyDropshipper($invoice, 'Payout invoice issued',
                    "Invoice {$invoice->invoice_number} for ৳" . number_format($total, 2) . " ({$invoice->orders_count} orders) is ready. Payment will follow.");
            }

            return $invoice;
        });
    }

    /** Pay an issued (pending) invoice. */
    public function pay(int $invoiceId, array $data, ?int $adminId): SettlementInvoice
    {
        return DB::transaction(function () use ($invoiceId, $data, $adminId) {
            $invoice = $this->lockPayout($invoiceId);
            if ($invoice->status !== SettlementInvoice::STATUS_PENDING) {
                throw new InvalidArgumentException("Invoice {$invoice->invoice_number} is {$invoice->status}; only an unpaid invoice can be paid.");
            }

            return $this->applyPayment($invoice, $data, $adminId);
        });
    }

    /** Withdraw an unpaid invoice; its orders become payable again. */
    public function cancel(int $invoiceId, ?string $reason, ?int $adminId): SettlementInvoice
    {
        return DB::transaction(function () use ($invoiceId, $reason, $adminId) {
            $invoice = $this->lockPayout($invoiceId);
            if ($invoice->status !== SettlementInvoice::STATUS_PENDING) {
                throw new InvalidArgumentException("Invoice {$invoice->invoice_number} is {$invoice->status}; only an unpaid invoice can be cancelled.");
            }

            $invoice->update([
                'status' => SettlementInvoice::STATUS_CANCELLED,
                'rejected_by' => $adminId,
                'rejected_at' => now(),
                'rejection_reason' => $reason ?: 'Cancelled by admin',
            ]);

            return $invoice->fresh();
        });
    }

    /* ---------------- internals ---------------- */

    private function applyPayment(SettlementInvoice $invoice, array $data, ?int $adminId): SettlementInvoice
    {
        $method = $data['payment_method'] ?? $invoice->payment_method ?? 'wallet';
        if (!in_array($method, self::PAYMENT_METHODS, true)) {
            throw new InvalidArgumentException('Unknown payment method.');
        }
        $isWallet = $method === 'wallet';
        $trxId = trim((string) ($data['trx_id'] ?? '')) ?: $invoice->invoice_number;
        if (!$isWallet && trim((string) ($data['trx_id'] ?? '')) === '' && $method !== 'cash') {
            throw new InvalidArgumentException('Enter the transaction ID of the payment.');
        }

        $dropshipperId = (int) $invoice->dropshipper_id;
        // Raw column: the model accessor would also load product details we don't need here.
        $raw = $invoice->getAttributes()['orders_breakdown'] ?? '[]';
        $rows = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
        $orders = Order::whereIn('id', array_column($rows, 'order_id'))->lockForUpdate()->get()->keyBy('id');
        $total = 0.00;

        foreach ($rows as $row) {
            $order = $orders->get((int) $row['order_id']);
            if (!$order) {
                throw new InvalidArgumentException("Order #{$row['order_id']} no longer exists.");
            }
            if (in_array((int) $order->status, self::DEAD_STATUSES, true)) {
                throw new InvalidArgumentException("Order {$order->order_number} was cancelled or returned after this invoice was issued. Cancel the invoice and create a new one.");
            }

            $amount = round((float) $row['profit'], 2);
            $settlement = OrderSettlement::firstOrCreate(
                ['order_id' => $order->id, 'settlement_type' => OrderSettlement::TYPE_RESELLER_PROFIT],
                [
                    'payable_user_id' => $dropshipperId,
                    'user_type' => 'dropshipper',
                    'gross_entitlement' => $amount,
                    'settleable_amount' => $amount,
                    'net_payable_amount' => $amount,
                    'currency' => 'BDT',
                    'status' => OrderSettlement::STATUS_PENDING,
                    'trx_id' => 'SETTLE-R-' . $order->id . '-' . time(),
                ]
            );
            if ($settlement->status === OrderSettlement::STATUS_SETTLED) {
                throw new InvalidArgumentException("Profit for order {$order->order_number} was already paid (trx {$settlement->settled_trx_id}).");
            }

            $settlement->update([
                'settleable_amount' => $amount,
                'net_payable_amount' => $amount,
                'status' => OrderSettlement::STATUS_SETTLED,
                // settled_trx_id is unique per settlement, so it can't hold the shared
                // payment trx id; the real one is on the invoice and in the note.
                'settled_trx_id' => $invoice->invoice_number . '-' . $order->id,
                'settled_at' => now(),
                'admin_note' => "Paid via payout invoice {$invoice->invoice_number}" . ($isWallet ? ' (wallet)' : " ({$method}, trx {$trxId})"),
            ]);

            if ($amount > 0) {
                // One earning credit per order (unique on order_id + type + trx_type).
                if (ResellerTransaction::where('order_id', $order->id)->where('type', 'order_settlement')->where('trx_type', 'credit')->exists()) {
                    throw new InvalidArgumentException("Order {$order->order_number} already has a profit credit in the dropshipper's ledger.");
                }
                ResellerTransaction::create([
                    'reseller_id' => $dropshipperId,
                    'amount' => $amount,
                    'trx_id' => ($isWallet ? '' : 'EARN-') . $invoice->invoice_number . '-' . $order->id,
                    'trx_type' => 'credit',
                    'type' => 'order_settlement',
                    'source' => $isWallet ? 'wallet' : 'order',
                    'order_id' => $order->id,
                    'ref_id' => $settlement->id,
                    'status' => 'completed',
                    'note' => "Profit for order #{$order->order_number} (payout invoice {$invoice->invoice_number})",
                ]);
            }

            $total += $amount;
        }
        $total = round($total, 2);

        // Sent outside the wallet: one transfer record for the whole invoice.
        if (!$isWallet && $total > 0) {
            $withdraw = WithdrawRequest::create([
                'user_id' => $dropshipperId,
                'amount' => $total,
                'reserved_amount' => 0.00,
                'status' => 'transferred',
                'payment_method' => $method,
                'bank_reference' => $trxId,
                'transferred_at' => now(),
                'note' => "Payout invoice {$invoice->invoice_number} ({$invoice->orders_count} orders)",
                'type' => 'direct_disbursement',
            ]);

            ResellerTransaction::create([
                'reseller_id' => $dropshipperId,
                'amount' => $total,
                'trx_id' => $trxId,
                'trx_type' => 'debit',
                'type' => 'withdraw',
                'source' => $method,
                'ref_id' => $withdraw->id,
                'status' => 'completed',
                'note' => "Payout invoice {$invoice->invoice_number} paid via {$method} (Ref: {$trxId})",
            ]);

            Transaction::create([
                'ref_id' => $withdraw->id,
                'type' => 'reseller_withdraw',
                'amount' => $total,
                'trx_id' => $trxId,
                'trx_type' => 'debit',
                'source' => $method,
                'status' => 'completed',
                'note' => "Dropshipper payout invoice {$invoice->invoice_number} via {$method} (Ref: {$trxId})",
            ]);
        }

        $invoice->update([
            'status' => SettlementInvoice::STATUS_PAID,
            'payment_method' => $method,
            'trx_id' => $trxId,
            'receiver_account' => $data['receiver_account'] ?? $invoice->receiver_account,
            'note' => $data['note'] ?? $invoice->note,
            'total_dropshipper_payout' => $total,
            'paid_amount' => $total,
            'paid_by' => $adminId,
            'paid_at' => now(),
            'settled_at' => now(),
        ]);

        foreach ($orders as $order) {
            $this->accounting->checkAndMarkOrderCompleted($order->fresh());
        }

        $where = $isWallet ? 'added to your wallet' : "sent via {$method} (Trx: {$trxId})";
        $this->notifyDropshipper($invoice, 'Profit payout received',
            '৳' . number_format($total, 2) . " for {$invoice->orders_count} orders was {$where}. Invoice {$invoice->invoice_number}.");

        return $invoice->fresh(['dropshipper', 'creator', 'payer']);
    }

    private function describeOrder(Order $order, array $reserved, $statusNames): array
    {
        $breakdown = $this->accounting->calculateOrderStakeBreakdown($order);
        $info = $order->deliveryInformation;
        $hasConsignment = $order->hasConsignment();
        $status = (int) $order->status;
        $profit = round((float) ($breakdown['dropshipper']['profit'] ?? 0), 2);

        $reason = null;
        if (isset($reserved[$order->id])) {
            $reason = "already on unpaid invoice {$reserved[$order->id]}";
        } elseif (in_array($status, self::DEAD_STATUSES, true)) {
            $reason = 'order is cancelled or returned';
        } elseif ($hasConsignment && !$order->is_carrybee_paid) {
            $reason = 'CarryBee has not paid yet';
        } elseif ($info?->needs_review) {
            $reason = 'courier review is waiting';
        }

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'ordered_at' => $order->created_at?->toIso8601String(),
            'customer_name' => $order->customer_name,
            'status' => $status,
            'status_name' => $statusNames[$status] ?? ('Status ' . $status),
            'carrybee_invoice_id' => $order->carrybee_invoice_id,
            'collected' => round((float) ($breakdown['dropshipper']['customer_collected'] ?? 0), 2),
            'admin_price' => round((float) ($breakdown['dropshipper']['admin_base_price'] ?? 0), 2),
            'delivery_charge' => round((float) ($breakdown['dropshipper']['customer_delivery_charge'] ?? 0), 2),
            'cod_fee' => round((float) ($breakdown['dropshipper']['cod_charge'] ?? 0), 2),
            'profit' => $profit,
            'payable' => $reason === null,
            'reason' => $reason,
        ];
    }

    /** order_id => invoice number, for orders sitting on an unpaid payout invoice. */
    private function reservedOrderIds(int $dropshipperId, ?int $ignoreInvoiceId): array
    {
        $reserved = [];
        SettlementInvoice::where('type', SettlementInvoice::TYPE_DROPSHIPPER_PAYOUT)
            ->where('dropshipper_id', $dropshipperId)
            ->where('status', SettlementInvoice::STATUS_PENDING)
            ->when($ignoreInvoiceId, fn ($q) => $q->where('id', '!=', $ignoreInvoiceId))
            ->get(['id', 'invoice_number', 'order_ids'])
            ->each(function (SettlementInvoice $invoice) use (&$reserved) {
                foreach ((array) $invoice->order_ids as $orderId) {
                    $reserved[(int) $orderId] = $invoice->invoice_number;
                }
            });

        return $reserved;
    }

    private function lockPayout(int $invoiceId): SettlementInvoice
    {
        $invoice = SettlementInvoice::where('type', SettlementInvoice::TYPE_DROPSHIPPER_PAYOUT)->lockForUpdate()->find($invoiceId);
        if (!$invoice) {
            throw new InvalidArgumentException('Payout invoice not found.');
        }

        return $invoice;
    }

    private function newInvoiceNumber(int $dropshipperId): string
    {
        do {
            $number = 'PAY-D' . $dropshipperId . '-' . now('Asia/Dhaka')->format('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        } while (SettlementInvoice::where('invoice_number', $number)->exists());

        return $number;
    }

    private function notifyDropshipper(SettlementInvoice $invoice, string $title, string $subtitle): void
    {
        $this->notifications->createNotificationSafely([
            'title' => $title, 'subtitle' => $subtitle, 'created_by' => null, 'send_to' => $invoice->dropshipper_id,
            'is_seen' => false, 'type' => 'payment', 'is_active' => true, 'image' => null, 'module' => 'payout',
        ]);
    }
}
