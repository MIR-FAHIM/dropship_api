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

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
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

            if (!$request->filled('status')) {
                $query->where('status', 'completed');
            }

            if ($request->filled('trx_type')) {
                $query->where('trx_type', $request->trx_type);
            }

            $totalCredit = (float) (clone $query)->where('trx_type', 'credit')->sum('amount');
            $totalDebit = (float) (clone $query)->where('trx_type', 'debit')->sum('amount');
            $items = $query->with('order')->latest()->paginate($perPage);

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

            return $this->success('Transaction report generated', [
                'total_credit' => $totalCredit,
                'total_debit' => $totalDebit,
                'profit' => $profit,
                'margin_percent' => $margin,
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

            $query = ResellerTransaction::where('status', 'completed')->where('reseller_id', $request->reseller_id);
            $this->applyDateFilters($query, $request);

            // Calculate sums for debit and credit
            $credit = (float) (clone $query)->where('trx_type', 'credit')->sum('amount');
            $debit = (float) (clone $query)->where('trx_type', 'debit')->sum('amount');

            $balance = $credit - $debit;

            // Reset query for items (remove trx_type filter)
            $items = ResellerTransaction::where('status', 'completed')->where('reseller_id', $request->reseller_id);
            $this->applyDateFilters($items, $request);
            $items = $items->latest()->paginate($perPage);

            return $this->success('Reseller transactions fetched', [
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $balance,
                'items' => $items,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
