<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\ResellerTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;

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
}
