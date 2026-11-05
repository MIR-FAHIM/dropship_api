<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Shops;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Cart;
use App\Models\Transaction;
use Carbon\Carbon;

class ReportController extends Controller
{
    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
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

    /**
     * Whether an order status row represents a cancelled/returned-style state,
     * matched against both its `type` and `name` columns (case-insensitive,
     * works whether the admin seeded English or Bengali labels).
     */
    private function statusMatches($status, array $keywords): bool
    {
        $haystack = mb_strtolower(trim(($status->type ?? '') . ' ' . ($status->name ?? '')));
        foreach ($keywords as $keyword) {
            if ($haystack !== '' && mb_strpos($haystack, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    private function percentChange(float $current, float $previous): float
    {
        if ($previous <= 0.0) {
            return $current > 0.0 ? 100.0 : 0.0;
        }
        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * GET /reports/dashboard
     *
     * Order & platform business report - counts, status breakdown, sales
     * (order totals), and day-wise growth. Deliberately excludes cashflow /
     * settlement / payout figures (vendor payable, platform margin, wallet
     * balances) - that belongs to a separate cashflow report.
     *
     * Query params:
     *   days (int, default 30, max 90) - how many days the daily_breakdown covers.
     */
    public function dashboard(Request $request)
    {
        try {
            $days = (int) $request->query('days', 30);
            $days = max(1, min(90, $days));

            $productsCount = Product::count();
            $totalVendor = User::where('user_type', 'vendor')->count();
            $totalAdmin = User::where('user_type', 'admin')->count();
            $totalDropshipper = User::where('user_type', 'dropshipper')->count();
            $customersCount = User::where('user_type', 'customer')->count();
            $activeCarts = Cart::where('status', 'active')->count();

            // ---- Order status breakdown (dynamic - whatever statuses actually exist) ----
            $statuses = OrderStatus::withCount('orders')->get();

            $cancelledStatusIds = $statuses
                ->filter(fn ($s) => $this->statusMatches($s, ['cancel', 'বাতিল']))
                ->pluck('id')->map('intval')->all();

            $returnedStatusIds = $statuses
                ->filter(fn ($s) => $this->statusMatches($s, ['return', 'ফেরত']))
                ->pluck('id')->map('intval')->all();

            $ordersGrossCount = (int) Order::count();
            $ordersCancelledCount = (int) Order::whereIn('status', $cancelledStatusIds)->count();
            $ordersReturnedCount = (int) Order::whereIn('status', $returnedStatusIds)->count();
            // "Total orders" the admin cares about excludes cancelled orders.
            $ordersNetCount = $ordersGrossCount - $ordersCancelledCount;

            $statusBreakdown = $statuses->map(function ($s) use ($ordersGrossCount) {
                return [
                    'status_id' => $s->id,
                    'name' => $s->name,
                    'type' => $s->type,
                    'count' => $s->orders_count,
                    'percent_of_total' => $ordersGrossCount > 0
                        ? round($s->orders_count / $ordersGrossCount * 100, 1)
                        : 0.0,
                ];
            })->sortByDesc('count')->values();

            // A fresh, non-cancelled orders query builder every time it's called
            // (Eloquent builders are mutable, so a shared instance can't be reused
            // across the several different date filters below).
            $netOrders = fn () => Order::whereNotIn('status', $cancelledStatusIds);

            // ---- Sales (order totals / GMV), excluding cancelled orders ----
            $totalSell = (float) $netOrders()->sum('total');

            $today = Carbon::today();
            $yesterday = Carbon::yesterday();

            $todaySell = (float) $netOrders()->whereDate('created_at', $today)->sum('total');
            $yesterdaySell = (float) $netOrders()->whereDate('created_at', $yesterday)->sum('total');

            $last7Start = Carbon::today()->subDays(6);
            $last7Sell = (float) $netOrders()->whereDate('created_at', '>=', $last7Start)->sum('total');

            // ---- Day-wise breakdown for the requested window (oldest -> newest) ----
            $periodStart = Carbon::today()->subDays($days - 1)->startOfDay();

            $grossByDay = Order::selectRaw('DATE(created_at) as d, COUNT(*) as cnt')
                ->where('created_at', '>=', $periodStart)
                ->groupBy('d')
                ->pluck('cnt', 'd');

            $cancelledByDay = Order::selectRaw('DATE(created_at) as d, COUNT(*) as cnt')
                ->where('created_at', '>=', $periodStart)
                ->whereIn('status', $cancelledStatusIds)
                ->groupBy('d')
                ->pluck('cnt', 'd');

            $revenueByDay = Order::selectRaw('DATE(created_at) as d, SUM(total) as rev')
                ->where('created_at', '>=', $periodStart)
                ->whereNotIn('status', $cancelledStatusIds)
                ->groupBy('d')
                ->pluck('rev', 'd');

            $dailyBreakdown = [];
            $legacyLast7 = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $d = Carbon::today()->subDays($i)->toDateString();
                $gross = (int) ($grossByDay[$d] ?? 0);
                $cancelled = (int) ($cancelledByDay[$d] ?? 0);
                $sell = round((float) ($revenueByDay[$d] ?? 0), 2);

                $dailyBreakdown[] = [
                    'date' => $d,
                    'orders_count' => $gross - $cancelled,
                    'orders_count_gross' => $gross,
                    'cancelled_count' => $cancelled,
                    'total_sell' => $sell,
                ];

                if ($i < 7) {
                    $legacyLast7[] = ['date' => $d, 'total' => $sell];
                }
            }
            $legacyLast7 = array_reverse($legacyLast7); // keep old "most recent first" order

            // ---- Period-over-period growth, so the admin can read trend, not just totals ----
            $thisWeekStart = Carbon::today()->subDays(6);
            $prevWeekStart = Carbon::today()->subDays(13);
            $prevWeekEnd = Carbon::today()->subDays(7);

            $thisWeekOrders = (int) $netOrders()->whereDate('created_at', '>=', $thisWeekStart)->count();
            $prevWeekOrders = (int) $netOrders()
                ->whereDate('created_at', '>=', $prevWeekStart)
                ->whereDate('created_at', '<=', $prevWeekEnd)
                ->count();
            $thisWeekSell = (float) $netOrders()->whereDate('created_at', '>=', $thisWeekStart)->sum('total');
            $prevWeekSell = (float) $netOrders()
                ->whereDate('created_at', '>=', $prevWeekStart)
                ->whereDate('created_at', '<=', $prevWeekEnd)
                ->sum('total');

            $thisMonthStart = Carbon::today()->startOfMonth();
            $dayOfMonth = Carbon::today()->day;
            $prevMonthStart = Carbon::today()->subMonthNoOverflow()->startOfMonth();
            // Compare like-for-like: only the same number of days into the previous month.
            $prevMonthComparableEnd = $prevMonthStart->copy()->addDays($dayOfMonth - 1);

            $thisMonthOrders = (int) $netOrders()->whereDate('created_at', '>=', $thisMonthStart)->count();
            $prevMonthOrders = (int) $netOrders()
                ->whereDate('created_at', '>=', $prevMonthStart)
                ->whereDate('created_at', '<=', $prevMonthComparableEnd)
                ->count();
            $thisMonthSell = (float) $netOrders()->whereDate('created_at', '>=', $thisMonthStart)->sum('total');
            $prevMonthSell = (float) $netOrders()
                ->whereDate('created_at', '>=', $prevMonthStart)
                ->whereDate('created_at', '<=', $prevMonthComparableEnd)
                ->sum('total');

            $data = [
                'products_count' => $productsCount,
                'total_vendor' => $totalVendor,
                'total_admin' => $totalAdmin,
                'total_dropshipper' => $totalDropshipper,
                'customers_count' => $customersCount,
                'active_carts' => $activeCarts,

                // Backward-compatible flat fields. orders_count now excludes
                // cancelled orders (previously it was a raw Order::count()).
                'orders_count' => $ordersNetCount,
                'total_sell' => round($totalSell, 2),
                'today_sell' => round($todaySell, 2),
                'yesterday_sell' => round($yesterdaySell, 2),
                'last_7_days_sell' => round($last7Sell, 2),
                'last_7_days_breakdown' => $legacyLast7,

                // New: order breakdown by status + cancelled/returned called out explicitly.
                'orders' => [
                    'total_gross' => $ordersGrossCount,
                    'total_net' => $ordersNetCount,
                    'cancelled_count' => $ordersCancelledCount,
                    'returned_count' => $ordersReturnedCount,
                    'status_breakdown' => $statusBreakdown,
                ],

                // New: day-wise growth report, oldest to newest.
                'daily_breakdown' => $dailyBreakdown,

                // New: period-over-period growth, so the admin can read a trend.
                'growth' => [
                    'week' => [
                        'current_orders' => $thisWeekOrders,
                        'previous_orders' => $prevWeekOrders,
                        'orders_growth_percent' => $this->percentChange($thisWeekOrders, $prevWeekOrders),
                        'current_sell' => round($thisWeekSell, 2),
                        'previous_sell' => round($prevWeekSell, 2),
                        'sell_growth_percent' => $this->percentChange($thisWeekSell, $prevWeekSell),
                    ],
                    'month' => [
                        'current_orders' => $thisMonthOrders,
                        'previous_orders' => $prevMonthOrders,
                        'orders_growth_percent' => $this->percentChange($thisMonthOrders, $prevMonthOrders),
                        'current_sell' => round($thisMonthSell, 2),
                        'previous_sell' => round($prevMonthSell, 2),
                        'sell_growth_percent' => $this->percentChange($thisMonthSell, $prevMonthSell),
                        'note' => 'Compares the first ' . $dayOfMonth . ' day(s) of this month vs the same day-count last month.',
                    ],
                ],
            ];

            return $this->success('Dashboard metrics fetched', $data);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
