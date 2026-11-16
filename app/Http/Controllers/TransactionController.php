<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\ResellerTransaction;
use App\Models\User;
use App\Models\WithdrawRequest;
use App\Models\WalletTopup;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
            'errors' => $errors
        ], $code);
    }

    private function applyDateFilters($query, Request $request)
    {
        if ($request->filled('start_date')) {
            $start = Carbon::parse($request->start_date)->startOfDay();
            $query->where('created_at', '>=', $start);
        }

        if ($request->filled('end_date')) {
            $end = Carbon::parse($request->end_date)->endOfDay();
            $query->where('created_at', '<=', $end);
        }

        return $query;
    }

    private function applyTransactionFilters($query, Request $request)
    {
        $this->applyDateFilters($query, $request);

        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        if ($request->filled('source') && $request->source !== 'all') {
            $query->where('source', $request->source);
        }

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('trx_type') && $request->trx_type !== 'all') {
            $query->where('trx_type', $request->trx_type);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('trx_id', 'like', "%{$search}%")
                  ->orWhere('ref_id', 'like', "%{$search}%")
                  ->orWhere('note', 'like', "%{$search}%")
                  ->orWhereHas('order', function ($oq) use ($search) {
                      $oq->where('order_number', 'like', "%{$search}%")
                         ->orWhere('customer_name', 'like', "%{$search}%");
                  });
            });
        }

        return $query;
    }

    /**
     * GET /transactions/all?trx_type=credit&type=order_status&start_date=2026-01-01&end_date=2026-01-31&per_page=20
     */
    public function allTransactions(Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 20);

            $query = Transaction::query();
            $this->applyTransactionFilters($query, $request);

            if (!$request->has('status') || $request->status === null) {
                $query->where('status', 'completed');
            }

            $totalCredit = (float) (clone $query)->where('trx_type', 'credit')->sum('amount');
            $totalDebit = (float) (clone $query)->where('trx_type', 'debit')->sum('amount');
            $items = $query->with([
                'order:id,order_number,customer_name,user_id',
                'order.user:id,name,phone'
            ])->latest()->paginate($perPage);

            return $this->success('Transactions fetched', [
                'total_credit' => $totalCredit,
                'total_debit' => $totalDebit,
                'balance' => $totalCredit - $totalDebit,
                'items' => $items,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /transactions/credit?start_date=2026-01-01&end_date=2026-01-31&per_page=20
     */
    public function creditTransaction(Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 20);

            $query = Transaction::where('trx_type', 'credit')
                ->where('status', 'completed');

            $this->applyTransactionFilters($query, $request);

            $total = (float) $query->sum('amount');
            $items = $query->latest()->paginate($perPage);

            return $this->success('Credit transactions fetched', [
                'total' => $total,
                'items' => $items,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /transactions/debit?start_date=2026-01-01&end_date=2026-01-31&per_page=20
     */
    public function debitTransaction(Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 20);

            $query = Transaction::where('trx_type', 'debit')
                ->where('status', 'completed');

            $this->applyTransactionFilters($query, $request);

            $total = (float) $query->sum('amount');
            $items = $query->latest()->paginate($perPage);

            return $this->success('Debit transactions fetched', [
                'total' => $total,
                'items' => $items,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /transactions/report?start_date=2026-01-01&end_date=2026-01-31
     */
    public function transactionReport(Request $request)
    {
        try {
            $baseQuery = Transaction::where('status', 'completed');
            $this->applyTransactionFilters($baseQuery, $request);

            $creditQuery = (clone $baseQuery)->where('trx_type', 'credit');
            $debitQuery = (clone $baseQuery)->where('trx_type', 'debit');

            $totalCredit = (float) $creditQuery->sum('amount');
            $totalDebit = (float) $debitQuery->sum('amount');
            $profit = $totalCredit - $totalDebit;
            $margin = $totalCredit > 0 ? round(($profit / $totalCredit) * 100, 2) : 0;
            $resellerTransactionQuery = ResellerTransaction::where('status', 'completed');
            $this->applyDateFilters($resellerTransactionQuery, $request);

            $resellerCredit = (float) (clone $resellerTransactionQuery)
                ->where('trx_type', 'credit')
                ->sum('amount');
            $resellerDebit = (float) (clone $resellerTransactionQuery)
                ->where('trx_type', 'debit')
                ->sum('amount');
            $resellerLiabilityBalance = $resellerCredit - $resellerDebit;

            $byType = (clone $baseQuery)
                ->select('type')
                ->selectRaw("SUM(CASE WHEN trx_type = 'credit' THEN amount ELSE 0 END) as total_credit")
                ->selectRaw("SUM(CASE WHEN trx_type = 'debit' THEN amount ELSE 0 END) as total_debit")
                ->groupBy('type')
                ->orderBy('type')
                ->get()
                ->map(function ($row) {
                    $credit = (float) $row->total_credit;
                    $debit = (float) $row->total_debit;

                    return [
                        'type' => $row->type,
                        'total_credit' => $credit,
                        'total_debit' => $debit,
                        'balance' => $credit - $debit,
                    ];
                });

            $vendorLiabilityBalance = (float) \App\Models\OrderSettlement::where('user_type', 'vendor')
                ->where('status', \App\Models\OrderSettlement::STATUS_PENDING)
                ->sum('settleable_amount');

            $advancesInCustody = (float) \App\Models\Order::sum('advance_amount');

            return $this->success('Transaction report generated', [
                'total_credit' => $totalCredit,
                'total_debit' => $totalDebit,
                'profit' => $profit,
                'margin_percent' => $margin,
                'reseller_liablity_balance' => $resellerLiabilityBalance,
                'reseller_liability_balance' => $resellerLiabilityBalance,
                'vendor_liability_balance' => $vendorLiabilityBalance,
                'advances_in_custody' => $advancesInCustody,
                'by_type' => $byType,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function resellerAdvanceRefunds(Request $request)
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $items = ResellerTransaction::query()
            ->where('type', 'advance_refund')
            ->with(['order:id,order_number', 'reseller:id,name'])
            ->latest()
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        foreach ($items as $transaction) {
            // History needs only the order reference. Order accessors expect a full order.
            $transaction->order?->setAppends([]);
        }

        return $this->success('Reseller advance refunds fetched', ['items' => $items]);
    }

    public function resellerAdvanceHistory(Request $request)
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        // Admin-entered advances can have a ledger booking without a reseller wallet transaction.
        $advanceEvents = DB::table('order_accounting_ledgers as ledger')
            ->join('orders', 'orders.id', '=', 'ledger.order_id')
            ->where('ledger.account_code', 'cash_platform')
            ->where(function ($query) {
                $query->where(fn ($booking) => $booking->where('ledger.entry_type', 'booking')->where('ledger.debit', '>', 0))
                    ->orWhere(fn ($reversal) => $reversal->where('ledger.entry_type', 'advance_reversal')->where('ledger.credit', '>', 0));
            })
            ->select('ledger.id', 'ledger.order_id', 'orders.user_id as reseller_id', 'ledger.reference_id as trx_id')
            ->selectRaw("CASE WHEN ledger.entry_type = 'booking' THEN ledger.debit ELSE ledger.credit END as amount")
            ->selectRaw("CASE WHEN ledger.entry_type = 'booking' THEN 'order_advance' ELSE 'advance_reversal' END as type")
            ->selectRaw("CASE WHEN ledger.entry_type = 'booking' THEN 'approved' ELSE 'reversed' END as status")
            ->addSelect('ledger.description as note', 'ledger.created_at');

        $refunds = DB::table('reseller_transactions')
            ->where('type', 'advance_refund')
            ->select('id', 'order_id', 'reseller_id', 'trx_id', 'amount', 'type', 'status', 'note', 'created_at');

        $items = DB::query()
            ->fromSub($advanceEvents->unionAll($refunds), 'events')
            ->leftJoin('users', 'users.id', '=', 'events.reseller_id')
            ->leftJoin('orders', 'orders.id', '=', 'events.order_id')
            ->select('events.*', 'users.name as reseller_name', 'orders.order_number')
            ->orderByDesc('events.created_at')
            ->orderByDesc('events.id')
            ->orderBy('events.type')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString()
            ->through(function ($event) {
                $event->created_at = $event->created_at ? Carbon::parse($event->created_at)->toIso8601String() : null;

                return $event;
            });

        return $this->success('Reseller advance history fetched', ['items' => $items]);
    }

    public function resellerTransactions(Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 20);
            $authUser = $request->user();
            $isAdmin = $authUser && in_array($authUser->user_type ?? $authUser->role, ['admin', 'superadmin']);
            $resellerId = $request->filled('reseller_id') ? (int) $request->reseller_id : (!$isAdmin ? $authUser?->id : null);

            $accountingService = app(\App\Service\OrderAccountingService::class);
            $balances = $resellerId ? $accountingService->calculateUserBalances((int) $resellerId) : null;

            $query = ResellerTransaction::with([
                'order:id,order_number,customer_name',
                'reseller:id,name,phone,email'
            ]);

            if ($request->filled('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            } elseif (!$request->has('status')) {
                $query->whereIn('status', ['completed', 'pending']);
            }

            if ($resellerId) {
                $query->where('reseller_id', $resellerId);
            }

            if ($request->filled('trx_type') && $request->trx_type !== 'all') {
                $query->where('trx_type', $request->trx_type);
            }

            if ($request->filled('type') && $request->type !== 'all') {
                $query->where('type', $request->type);
            }

            if ($request->filled('search')) {
                $search = trim($request->search);
                $query->where(function ($q) use ($search) {
                    $q->where('trx_id', 'like', "%{$search}%")
                      ->orWhere('ref_id', 'like', "%{$search}%")
                      ->orWhere('note', 'like', "%{$search}%")
                      ->orWhereHas('order', function ($oq) use ($search) {
                          $oq->where('order_number', 'like', "%{$search}%")
                             ->orWhere('customer_name', 'like', "%{$search}%");
                      })
                      ->orWhereHas('reseller', function ($rq) use ($search) {
                          $rq->where('name', 'like', "%{$search}%")
                             ->orWhere('phone', 'like', "%{$search}%")
                             ->orWhere('email', 'like', "%{$search}%");
                      });
                });
            }

            $this->applyDateFilters($query, $request);

            if (!$balances) {
                $compQuery = (clone $query)->where('status', 'completed');
                $totalCredit = (float) (clone $compQuery)->where('trx_type', 'credit')->sum('amount');
                $totalDebit = (float) (clone $compQuery)->where('trx_type', 'debit')->sum('amount');
                $pendingReserved = (float) (clone $query)->where('status', 'pending')->where('trx_type', 'debit')->sum('amount');
                $balances = [
                    'total_credit' => $totalCredit,
                    'total_debit' => $totalDebit,
                    'net_balance' => $totalCredit - $totalDebit,
                    'pending_reserved' => $pendingReserved,
                    'available_balance' => ($totalCredit - $totalDebit) - $pendingReserved,
                ];
            }

            $items = $query->latest()->paginate($perPage);

            return $this->success('Reseller transactions fetched', [
                'debit' => $balances['total_debit'],
                'credit' => $balances['total_credit'],
                'balance' => $balances['net_balance'],
                'pending_reserved' => $balances['pending_reserved'] ?? 0,
                'available_balance' => $balances['available_balance'] ?? $balances['net_balance'],
                'items' => $items,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /** Admin: fetch all wallet transaction types for one dropshipper. */
    public function dropshipperTransactions(Request $request, $dropshipperId)
    {
        $request->merge(['dropshipper_id' => $dropshipperId]);
        $validated = $request->validate([
            'dropshipper_id' => ['required', 'integer', 'exists:users,id'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'string', 'max:30'],
            'trx_type' => ['sometimes', 'string', 'max:30'],
            'type' => ['sometimes', 'string', 'max:60'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date'],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);

        if (!empty($validated['start_date']) && !empty($validated['end_date'])
            && Carbon::parse($validated['end_date'])->lt(Carbon::parse($validated['start_date']))) {
            return $this->failed('Validation failed', ['end_date' => ['The end date must be on or after the start date.']], 422);
        }

        $dropshipper = User::where('user_type', 'dropshipper')->find($validated['dropshipper_id']);
        if (!$dropshipper) {
            return $this->failed('Dropshipper not found', null, 404);
        }

        $userId = (int) $dropshipper->id;
        $perPage = (int) ($validated['per_page'] ?? 20);
        $accounting = app(\App\Service\OrderAccountingService::class);
        $balance = $accounting->calculateUserBalances($userId);
        $labels = [
            'wallet_topup' => 'Wallet top-up',
            'order_settlement' => 'Order profit',
            'withdraw' => 'Withdrawal or payout',
            'order_advance' => 'Reliability advance',
            'advance_refund' => 'Advance refund',
            'profit_clawback' => 'Order profit reversal',
        ];

        $ledgerQuery = ResellerTransaction::with(['order:id,order_number,customer_name'])
            ->where('reseller_id', $userId);
        if (!empty($validated['status']) && $validated['status'] !== 'all') {
            $ledgerQuery->where('status', $validated['status']);
        }
        if (!empty($validated['trx_type']) && $validated['trx_type'] !== 'all') {
            $ledgerQuery->where('trx_type', $validated['trx_type']);
        }
        if (!empty($validated['type']) && $validated['type'] !== 'all') {
            $ledgerQuery->where('type', $validated['type']);
        }
        if (!empty($validated['search'])) {
            $term = trim($validated['search']);
            $ledgerQuery->where(function ($query) use ($term) {
                $query->where('trx_id', 'like', "%{$term}%")
                    ->orWhere('ref_id', 'like', "%{$term}%")
                    ->orWhere('note', 'like', "%{$term}%")
                    ->orWhereHas('order', fn ($order) => $order
                        ->where('order_number', 'like', "%{$term}%")
                        ->orWhere('customer_name', 'like', "%{$term}%"));
            });
        }
        $this->applyDateFilters($ledgerQuery, $request);

        $transactions = $ledgerQuery->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage)->withQueryString();
        $transactions->getCollection()->transform(function (ResellerTransaction $transaction) use ($labels) {
            $status = strtolower((string) $transaction->status);
            $direction = strtolower((string) $transaction->trx_type);
            $amount = round((float) $transaction->amount, 2);
            $balanceEffect = $status === 'completed'
                ? ($direction === 'credit' ? $amount : ($direction === 'debit' ? -$amount : 0.00))
                : 0.00;

            return [
                'id' => $transaction->id,
                'category' => $transaction->type,
                'category_label' => $labels[$transaction->type] ?? str($transaction->type)->replace('_', ' ')->title()->toString(),
                'direction' => $transaction->trx_type,
                'amount' => $amount,
                'balance_effect' => $balanceEffect,
                'status' => $transaction->status,
                'status_affects_balance' => $status === 'completed',
                'transaction_id' => $transaction->trx_id,
                'reference_id' => $transaction->ref_id,
                'source' => $transaction->source,
                'note' => $transaction->note,
                'order' => $transaction->order ? [
                    'id' => $transaction->order->id,
                    'order_number' => $transaction->order->order_number,
                    'customer_name' => $transaction->order->customer_name,
                ] : null,
                'created_at' => $transaction->created_at?->toIso8601String(),
            ];
        });

        $totalsByCategory = ResellerTransaction::where('reseller_id', $userId)
            ->select('type', 'trx_type', 'status')
            ->selectRaw('COUNT(*) as transaction_count, SUM(amount) as amount_total')
            ->groupBy('type', 'trx_type', 'status')
            ->orderBy('type')->get()
            ->map(fn ($row) => [
                'category' => $row->type,
                'category_label' => $labels[$row->type] ?? str($row->type)->replace('_', ' ')->title()->toString(),
                'direction' => $row->trx_type,
                'status' => $row->status,
                'count' => (int) $row->transaction_count,
                'amount_total' => round((float) $row->amount_total, 2),
            ]);

        $withdrawals = WithdrawRequest::where('user_id', $userId)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage, ['id', 'user_id', 'amount', 'reserved_amount', 'status', 'payment_method', 'bank_reference', 'note', 'type', 'transferred_at', 'created_at'], 'withdrawals_page')
            ->withQueryString();

        $topups = WalletTopup::where('user_id', $userId)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage, ['id', 'user_id', 'amount', 'payment_method', 'sender_phone', 'trx_id', 'status', 'approved_by', 'approved_at', 'admin_note', 'created_at'], 'topups_page')
            ->withQueryString();

        $pendingCredits = ResellerTransaction::where('reseller_id', $userId)
            ->where('status', 'pending')->where('trx_type', 'credit')->sum('amount');

        return $this->success('Dropshipper account history fetched', [
            'dropshipper' => [
                'id' => $dropshipper->id,
                'name' => $dropshipper->name,
                'email' => $dropshipper->email,
                'phone' => $dropshipper->phone,
            ],
            'summary' => [
                'currency' => 'BDT',
                'completed_credits' => $balance['total_credit'],
                'completed_debits' => $balance['total_debit'],
                'net_wallet_balance' => $balance['net_balance'],
                'pending_withdrawal_reserved' => $balance['pending_reserved'],
                'available_balance' => $balance['available_balance'],
                'pending_credits_not_yet_in_balance' => round((float) $pendingCredits, 2),
                'transaction_count' => ResellerTransaction::where('reseller_id', $userId)->count(),
                'withdrawal_request_count' => WithdrawRequest::where('user_id', $userId)->count(),
                'topup_request_count' => WalletTopup::where('user_id', $userId)->count(),
            ],
            'totals_by_category' => $totalsByCategory,
            'transactions' => $transactions,
            'withdrawal_requests' => $withdrawals,
            'wallet_topup_requests' => $topups,
        ]);
    }
}
