<?php

namespace App\Http\Controllers;

use App\Models\ProductClicks;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ProductClicksController extends Controller
{
    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json([
            'status'  => 'failed',
            'message' => $message,
            'errors'  => $errors,
        ], $code);
    }

    /**
     * GET /product-clicks/report/monthwise?year=2026
     * Returns total clicks grouped by month for the given year.
     */
    public function monthwiseReport(Request $request)
    {
        $request->validate([
            'year' => 'nullable|integer|min:2000|max:2100',
        ]);

        try {
            $year = (int) ($request->input('year', Carbon::now()->year));

            $rows = ProductClicks::selectRaw('MONTH(created_at) as month, COUNT(*) as total_clicks')
                ->whereYear('created_at', $year)
                ->groupByRaw('MONTH(created_at)')
                ->orderByRaw('MONTH(created_at)')
                ->get()
                ->keyBy('month');

            $months = [];
            for ($m = 1; $m <= 12; $m++) {
                $months[] = [
                    'month'        => $m,
                    'month_name'   => Carbon::createFromDate($year, $m, 1)->format('F'),
                    'total_clicks' => isset($rows[$m]) ? (int) $rows[$m]->total_clicks : 0,
                ];
            }

            return $this->success('Monthwise product clicks report', [
                'year'   => $year,
                'report' => $months,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /product-clicks/report/daywise?year=2026&month=5
     * Returns total clicks grouped by day for the given month and year.
     */
    public function daywiseReport(Request $request)
    {
        $request->validate([
            'year'  => 'nullable|integer|min:2000|max:2100',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        try {
            $year  = (int) ($request->input('year', Carbon::now()->year));
            $month = (int) ($request->input('month', Carbon::now()->month));

            $daysInMonth = Carbon::createFromDate($year, $month, 1)->daysInMonth;

            $rows = ProductClicks::selectRaw('DAY(created_at) as day, COUNT(*) as total_clicks')
                ->whereYear('created_at', $year)
                ->whereMonth('created_at', $month)
                ->groupByRaw('DAY(created_at)')
                ->orderByRaw('DAY(created_at)')
                ->get()
                ->keyBy('day');

            $days = [];
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $days[] = [
                    'date'         => Carbon::createFromDate($year, $month, $d)->toDateString(),
                    'total_clicks' => isset($rows[$d]) ? (int) $rows[$d]->total_clicks : 0,
                ];
            }

            return $this->success('Daywise product clicks report', [
                'year'   => $year,
                'month'  => $month,
                'report' => $days,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /product-clicks/report/last-7-days
     * Returns total clicks for each of the last 7 days (including today).
     */
    public function last7DaysReport()
    {
        try {
            $start = Carbon::today()->subDays(6);

            $rows = ProductClicks::selectRaw('DATE(created_at) as date, COUNT(*) as total_clicks')
                ->where('created_at', '>=', $start)
                ->groupByRaw('DATE(created_at)')
                ->orderByRaw('DATE(created_at)')
                ->get()
                ->keyBy('date');

            $days = [];
            for ($i = 6; $i >= 0; $i--) {
                $date   = Carbon::today()->subDays($i)->toDateString();
                $days[] = [
                    'date'         => $date,
                    'total_clicks' => isset($rows[$date]) ? (int) $rows[$date]->total_clicks : 0,
                ];
            }

            $totalClicks = array_sum(array_column($days, 'total_clicks'));

            return $this->success('Last 7 days product clicks report', [
                'total_clicks' => $totalClicks,
                'report'       => $days,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /product-clicks/leaderboard?limit=10&start_date=2026-01-01&end_date=2026-05-31
     * Returns top products ranked by total click count.
     */
    public function leaderboard(Request $request)
    {
        $request->validate([
            'limit'      => 'nullable|integer|min:1|max:100',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
        ]);

        try {
            $limit = (int) ($request->input('limit', 10));

            $query = ProductClicks::selectRaw('product_id, COUNT(*) as total_clicks')
                ->groupBy('product_id')
                ->orderByDesc('total_clicks')
                ->limit($limit);

            if ($request->filled('start_date')) {
                $query->whereDate('created_at', '>=', $request->input('start_date'));
            }

            if ($request->filled('end_date')) {
                $query->whereDate('created_at', '<=', $request->input('end_date'));
            }

            $rows = $query->with('product:id,name,sku')->get();

            $leaderboard = $rows->map(function ($row, $index) {
                return [
                    'rank'         => $index + 1,
                    'product_id'   => $row->product_id,
                    'product'      => $row->product,
                    'total_clicks' => (int) $row->total_clicks,
                ];
            });

            return $this->success('Product clicks leaderboard', [
                'limit'       => $limit,
                'leaderboard' => $leaderboard,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
