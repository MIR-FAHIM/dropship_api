<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Shops;
use App\Models\User;
use App\Models\Order;
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
     * GET /reports/dashboard
     * Returns summary counts and sales metrics
     */
    public function dashboard(Request $request)
    {
        try {
            $productsCount = Product::count();
            $shopsCount = Shops::count();
            // Users use `user_type` (default 'customer') in the users table
            $customersCount = User::where('user_type', 'customer')->count();

            $ordersCount = Order::count();
            $activeCarts = Cart::where('status', 'active')->count();

            // Sales sums (only consider completed credit transactions)
            $totalSell = (float) Transaction::where('trx_type', 'credit')
                ->where('status', 'completed')
                ->sum('amount');

            $today = Carbon::today()->toDateString();
            $yesterday = Carbon::yesterday()->toDateString();

            $todaySell = (float) Transaction::where('trx_type', 'credit')
                ->where('status', 'completed')
                ->whereDate('created_at', $today)
                ->sum('amount');

            $yesterdaySell = (float) Transaction::where('trx_type', 'credit')
                ->where('status', 'completed')
                ->whereDate('created_at', $yesterday)
                ->sum('amount');

            $last7Start = Carbon::today()->subDays(6)->toDateString(); // include today = 7 days
            $last7Sell = (float) Transaction::where('trx_type', 'credit')
                ->where('status', 'completed')
                ->whereDate('created_at', '>=', $last7Start)
                ->sum('amount');

            // Daily breakdown for last 7 days (most recent first)
            $days = [];
            for ($i = 0; $i < 7; $i++) {
                $d = Carbon::today()->subDays($i)->toDateString();
                $sum = (float) Transaction::where('trx_type', 'credit')
                    ->where('status', 'completed')
                    ->whereDate('created_at', $d)
                    ->sum('amount');
                $days[] = [
                    'date' => $d,
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
