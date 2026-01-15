<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Shops;
use App\Models\User;
use App\Models\Order;
use App\Models\Cart;
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
     * GET /reports/dashboard
     * Returns summary counts and sales metrics
     */
    public function dashboard(Request $request)
    {
        try {
            $productsCount = Product::count();
            $shopsCount = Shops::count();
            $customersCount = User::where('role', 'customer')->count();

            $ordersCount = Order::count();
            $activeCarts = Cart::where('status', 'active')->count();

            // Sales sums (only consider paid orders)
            $totalSell = (float) Order::where('payment_status', 'paid')->sum('total');

            $today = Carbon::today();
            $yesterday = Carbon::yesterday();

            $todaySell = (float) Order::where('payment_status', 'paid')
                ->whereDate('created_at', $today)
                ->sum('total');

            $yesterdaySell = (float) Order::where('payment_status', 'paid')
                ->whereDate('created_at', $yesterday)
                ->sum('total');

            $last7Start = Carbon::today()->subDays(6); // include today = 7 days
            $last7Sell = (float) Order::where('payment_status', 'paid')
                ->whereDate('created_at', '>=', $last7Start)
                ->sum('total');

            // Daily breakdown for last 7 days
            $days = [];
            for ($i = 0; $i < 7; $i++) {
                $d = Carbon::today()->subDays(6 - $i);
                $sum = (float) Order::where('payment_status', 'paid')
                    ->whereDate('created_at', $d)
                    ->sum('total');
                $days[] = [
                    'date' => $d->toDateString(),
                    'total' => $sum,
                ];
            }

            $data = [
                'products_count' => $productsCount,
                'shops_count' => $shopsCount,
                'customers_count' => $customersCount,
                'orders_count' => $ordersCount,
                'active_carts' => $activeCarts,
                'total_sell' => $totalSell,
                'today_sell' => $todaySell,
                'yesterday_sell' => $yesterdaySell,
                'last_7_days_sell' => $last7Sell,
                'last_7_days_breakdown' => $days,
            ];

            return $this->success('Dashboard metrics fetched', $data);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
