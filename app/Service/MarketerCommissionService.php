<?php

namespace App\Service;

use App\Models\MarketerClient;
use App\Models\MarketerCommission;
use App\Models\Order;
use App\Models\SettlementInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Partner Portal — marketer commission.
 *
 * Earn:   OrderAccountingService::processOrderDelivery (runs once, when an order is delivered
 *         and CarryBee has paid) calls earnForOrder() with the platform's product markup and
 *         delivery margin. Commission = rate% × (markup + delivery margin), never below 0.
 * Undo:   processOrderReversal (cancelled / returned / lost) calls reverseForOrder():
 *         not yet on a payout → status "reversed"; already on a payout (paid or not) →
 *         a negative "clawback" line that is taken off the marketer's next payout.
 * Payout: createPayout() puts payable lines on a settlement_invoices row of type
 *         marketer_commission (unpaid or paid at once); pay() / cancel() finish it.
 */
class MarketerCommissionService
{
    public const PAYMENT_METHODS = ['bkash', 'nagad', 'rocket', 'bank_transfer', 'cash'];

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function rateFor(?User $marketer): float
    {
        $rate = $marketer?->marketer_commission_rate;

        return round((float) ($rate !== null ? $rate : config('marketer.commission_rate', 20)), 2);
    }

    /* ---------------- earning / reversal (called from order accounting) ---------------- */

    public function earnForOrder(Order $order, float $productMarkup, float $deliveryMargin): ?MarketerCommission
    {
        if (!$order->marketer_client_id) {
            return null;
        }
        $client = MarketerClient::find($order->marketer_client_id);
        $marketer = $client ? User::find($client->marketer_user_id) : null;
        if (!$client || !$marketer) {
            return null;
        }

        $existing = MarketerCommission::where('order_id', $order->id)->where('type', MarketerCommission::TYPE_COMMISSION)->first();
        if ($existing) {
            return $existing; // delivery accounting runs once; never earn twice
        }

        $markup = round($productMarkup, 2);
        $margin = round($deliveryMargin, 2);
        $base = round($markup + $margin, 2);
        $rate = $this->rateFor($marketer);
        $amount = round(max(0, $base) * $rate / 100, 2);

        return MarketerCommission::create([
            'marketer_user_id' => $marketer->id,
            'marketer_client_id' => $client->id,
            'order_id' => $order->id,
            'type' => MarketerCommission::TYPE_COMMISSION,
            'product_markup' => $markup,
            'delivery_margin' => $margin,
            'base_amount' => $base,
            'rate' => $rate,
            'amount' => $amount,
            'status' => MarketerCommission::STATUS_EARNED,
            'earned_at' => now(),
            'note' => "{$rate}% of (markup ৳{$markup} + delivery margin ৳{$margin})",
        ]);
    }

    public function reverseForOrder(Order $order, string $reason): void
    {
        $commission = MarketerCommission::where('order_id', $order->id)
            ->where('type', MarketerCommission::TYPE_COMMISSION)->lockForUpdate()->first();
        if (!$commission || $commission->status === MarketerCommission::STATUS_REVERSED) {
            return;
        }

        if ($commission->status === MarketerCommission::STATUS_EARNED && !$commission->payout_invoice_id) {
            $commission->update(['status' => MarketerCommission::STATUS_REVERSED, 'reversed_at' => now(), 'note' => trim($commission->note . " · Reversed: {$reason}")]);

            return;
        }

        // Already on a payout: take it back from the next one (once).
        if ($commission->amount > 0 && !MarketerCommission::where('order_id', $order->id)->where('type', MarketerCommission::TYPE_CLAWBACK)->exists()) {
            MarketerCommission::create([
                'marketer_user_id' => $commission->marketer_user_id,
                'marketer_client_id' => $commission->marketer_client_id,
                'order_id' => $order->id,
                'type' => MarketerCommission::TYPE_CLAWBACK,
                'base_amount' => -$commission->base_amount,
                'rate' => $commission->rate,
                'amount' => -$commission->amount,
                'status' => MarketerCommission::STATUS_EARNED,
                'earned_at' => now(),
                'note' => "Taken back: order {$order->order_number} {$reason}",
            ]);
        }
    }

    /* ---------------- payouts (admin) ---------------- */

    /**
     * @param array|null $commissionIds null = every payable line of this marketer
     * @param array $data payment_method, trx_id, receiver_account, note, status (paid|pending)
     */
    public function createPayout(int $marketerId, ?array $commissionIds, array $data, ?int $adminId): SettlementInvoice
    {
        return DB::transaction(function () use ($marketerId, $commissionIds, $data, $adminId) {
            $marketer = User::where('user_type', 'marketer')->lockForUpdate()->find($marketerId);
            if (!$marketer) {
                throw new InvalidArgumentException('Marketer not found.');
            }

            $query = MarketerCommission::with('order:id,order_number')->where('marketer_user_id', $marketerId)->payable()->lockForUpdate();
            if ($commissionIds !== null) {
                $query->whereIn('id', array_map('intval', $commissionIds));
            }
            $rows = $query->orderBy('id')->get();
            if ($commissionIds !== null && $rows->count() !== count(array_unique($commissionIds))) {
                throw new InvalidArgumentException('Some selected commissions are already paid, on another payout or reversed. Reload and try again.');
            }
            $total = round((float) $rows->sum('amount'), 2);
            if ($rows->isEmpty() || $total <= 0) {
                throw new InvalidArgumentException('Nothing to pay: the selected commissions add up to ৳' . number_format($total, 2) . '.');
            }

            $invoice = SettlementInvoice::create([
                'invoice_number' => $this->newInvoiceNumber($marketerId),
                'marketer_id' => $marketerId,
                'user_id' => $adminId,
                'type' => SettlementInvoice::TYPE_MARKETER_COMMISSION,
                'orders_count' => $rows->where('type', MarketerCommission::TYPE_COMMISSION)->count(),
                'total_platform_net_profit' => round((float) $rows->sum('base_amount'), 2),
                'total_amount_owed' => $total,
                'calculated_amount' => $total,
                'adjustment_amount' => 0,
                // Column is required; for an unpaid payout this is only the planned method (set again when paid).
                'payment_method' => $data['payment_method'] ?? 'bkash',
                'status' => SettlementInvoice::STATUS_PENDING,
                'order_ids' => $rows->pluck('order_id')->unique()->values()->all(),
                'orders_breakdown' => $rows->map(fn (MarketerCommission $c) => [
                    'commission_id' => $c->id,
                    'order_id' => $c->order_id,
                    'order_number' => $c->order?->order_number,
                    'type' => $c->type,
                    'base' => $c->base_amount,
                    'rate' => $c->rate,
                    'amount' => $c->amount,
                ])->values()->all(),
                'note' => $data['note'] ?? null,
            ]);
            MarketerCommission::whereIn('id', $rows->pluck('id'))->update(['payout_invoice_id' => $invoice->id]);

            if (($data['status'] ?? SettlementInvoice::STATUS_PAID) === SettlementInvoice::STATUS_PAID) {
                return $this->applyPayment($invoice, $data, $adminId);
            }
            $this->notify($marketerId, 'Commission payout issued', "Payout {$invoice->invoice_number} for ৳" . number_format($total, 2) . ' is ready. Payment will follow.');

            return $invoice->fresh();
        });
    }

    public function pay(int $invoiceId, array $data, ?int $adminId): SettlementInvoice
    {
        return DB::transaction(function () use ($invoiceId, $data, $adminId) {
            $invoice = $this->lockInvoice($invoiceId);
            if ($invoice->status !== SettlementInvoice::STATUS_PENDING) {
                throw new InvalidArgumentException("Payout {$invoice->invoice_number} is {$invoice->status}; only an unpaid one can be paid.");
            }

            return $this->applyPayment($invoice, $data, $adminId);
        });
    }

    public function cancel(int $invoiceId, ?string $reason, ?int $adminId): SettlementInvoice
    {
        return DB::transaction(function () use ($invoiceId, $reason, $adminId) {
            $invoice = $this->lockInvoice($invoiceId);
            if ($invoice->status !== SettlementInvoice::STATUS_PENDING) {
                throw new InvalidArgumentException("Payout {$invoice->invoice_number} is {$invoice->status}; only an unpaid one can be cancelled.");
            }
            $invoice->update([
                'status' => SettlementInvoice::STATUS_CANCELLED,
                'rejected_by' => $adminId,
                'rejected_at' => now(),
                'rejection_reason' => $reason ?: 'Cancelled by admin',
            ]);
            // Lines become payable again.
            MarketerCommission::where('payout_invoice_id', $invoice->id)->where('status', MarketerCommission::STATUS_EARNED)
                ->update(['payout_invoice_id' => null]);

            return $invoice->fresh();
        });
    }

    /* ---------------- summaries ---------------- */

    /** Totals for one marketer (panel cards) or, with null, per marketer for the admin page. */
    public function totals(?int $marketerId = null): array
    {
        $q = MarketerCommission::query()
            ->leftJoin('settlement_invoices as si', 'si.id', '=', 'marketer_commissions.payout_invoice_id')
            ->selectRaw('marketer_commissions.marketer_user_id AS marketer_id')
            ->selectRaw("ROUND(COALESCE(SUM(CASE WHEN marketer_commissions.status = 'earned' AND marketer_commissions.payout_invoice_id IS NULL THEN marketer_commissions.amount END), 0), 2) AS payable")
            ->selectRaw("ROUND(COALESCE(SUM(CASE WHEN marketer_commissions.status = 'earned' AND si.status = 'pending' THEN marketer_commissions.amount END), 0), 2) AS invoiced")
            ->selectRaw("ROUND(COALESCE(SUM(CASE WHEN marketer_commissions.status = 'paid' THEN marketer_commissions.amount END), 0), 2) AS paid")
            ->selectRaw("ROUND(COALESCE(SUM(CASE WHEN marketer_commissions.status IN ('earned', 'paid') THEN marketer_commissions.amount END), 0), 2) AS earned_total")
            ->selectRaw("COUNT(CASE WHEN marketer_commissions.type = 'commission' AND marketer_commissions.status <> 'reversed' THEN 1 END) AS commissioned_orders")
            ->groupBy('marketer_commissions.marketer_user_id');
        if ($marketerId !== null) {
            $q->where('marketer_commissions.marketer_user_id', $marketerId);
        }
        $rows = $q->toBase()->get()->keyBy('marketer_id')->map(fn ($r) => [
            'payable' => (float) $r->payable,
            'invoiced' => (float) $r->invoiced,
            'paid' => (float) $r->paid,
            'earned_total' => (float) $r->earned_total,
            'commissioned_orders' => (int) $r->commissioned_orders,
        ]);

        if ($marketerId !== null) {
            return $rows->get($marketerId) ?? ['payable' => 0.0, 'invoiced' => 0.0, 'paid' => 0.0, 'earned_total' => 0.0, 'commissioned_orders' => 0];
        }

        return $rows->all();
    }

    /* ---------------- internals ---------------- */

    private function applyPayment(SettlementInvoice $invoice, array $data, ?int $adminId): SettlementInvoice
    {
        $method = $data['payment_method'] ?? $invoice->payment_method;
        if (!in_array($method, self::PAYMENT_METHODS, true)) {
            throw new InvalidArgumentException('Choose how the commission was paid (bKash, Nagad, Rocket, bank or cash).');
        }
        $trxId = trim((string) ($data['trx_id'] ?? ''));
        if ($trxId === '' && $method !== 'cash') {
            throw new InvalidArgumentException('Enter the transaction ID of the payment.');
        }

        $invoice->update([
            'status' => SettlementInvoice::STATUS_PAID,
            'payment_method' => $method,
            'trx_id' => $trxId ?: $invoice->invoice_number,
            'receiver_account' => $data['receiver_account'] ?? null,
            'paid_by' => $adminId,
            'paid_amount' => $invoice->total_amount_owed,
            'paid_at' => now(),
            'settled_at' => now(),
        ]);
        MarketerCommission::where('payout_invoice_id', $invoice->id)->where('status', MarketerCommission::STATUS_EARNED)
            ->update(['status' => MarketerCommission::STATUS_PAID, 'paid_at' => now()]);

        $this->notify((int) $invoice->marketer_id, 'Commission paid',
            "৳" . number_format((float) $invoice->total_amount_owed, 2) . " commission paid ({$invoice->invoice_number}, {$method}).");

        return $invoice->fresh();
    }

    private function lockInvoice(int $invoiceId): SettlementInvoice
    {
        $invoice = SettlementInvoice::where('type', SettlementInvoice::TYPE_MARKETER_COMMISSION)->lockForUpdate()->find($invoiceId);
        if (!$invoice) {
            throw new InvalidArgumentException('Commission payout not found.');
        }

        return $invoice;
    }

    private function newInvoiceNumber(int $marketerId): string
    {
        do {
            $number = 'COM-M' . $marketerId . '-' . now('Asia/Dhaka')->format('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        } while (SettlementInvoice::where('invoice_number', $number)->exists());

        return $number;
    }

    private function notify(int $userId, string $title, string $subtitle): void
    {
        $this->notifications->createNotificationSafely([
            'title' => $title, 'subtitle' => $subtitle, 'created_by' => null, 'send_to' => $userId,
            'is_seen' => false, 'type' => 'payment', 'is_active' => true, 'image' => null, 'module' => 'marketer_commission',
        ]);
    }
}
