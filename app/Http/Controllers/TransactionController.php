<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
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

    /**
     * GET /transactions/credit?start_date=2026-01-01&end_date=2026-01-31&per_page=20
     */
    public function creditTransaction(Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 20);

            $query = Transaction::where('trx_type', 'credit')
                ->where('status', 'completed');

            $this->applyDateFilters($query, $request);

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

            $this->applyDateFilters($query, $request);

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
            $creditQuery = Transaction::where('trx_type', 'credit')
                ->where('status', 'completed');
            $debitQuery = Transaction::where('trx_type', 'debit')
                ->where('status', 'completed');

            $this->applyDateFilters($creditQuery, $request);
            $this->applyDateFilters($debitQuery, $request);

            $totalCredit = (float) $creditQuery->sum('amount');
            $totalDebit = (float) $debitQuery->sum('amount');
            $profit = $totalCredit - $totalDebit;
            $margin = $totalCredit > 0 ? round(($profit / $totalCredit) * 100, 2) : 0;

            return $this->success('Transaction report generated', [
                'total_credit' => $totalCredit,
                'total_debit' => $totalDebit,
                'profit' => $profit,
                'margin_percent' => $margin,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
