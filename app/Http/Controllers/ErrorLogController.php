<?php

namespace App\Http\Controllers;

use App\Models\LoginError;
use App\Models\LoginSuccessLog;
use App\Models\OrderErrorLog;
use App\Models\ProductCreateErrorLog;
use App\Models\RegistrationErrorLog;
use Illuminate\Http\Request;

class ErrorLogController extends Controller
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
            'errors' => $errors,
        ], $code);
    }

    public function overallReport()
    {
        try {
            $now = now();
            $todayStart = $now->copy()->startOfDay();
            $lastHourStart = $now->copy()->subHour();

            $sources = [
                'product_create' => ProductCreateErrorLog::query(),
                'login' => LoginError::query(),
                'registration' => RegistrationErrorLog::query(),
                'order' => OrderErrorLog::query(),
            ];

            $todaySeparated = [];
            $lastHourSeparated = [];

            foreach ($sources as $key => $baseQuery) {
                $todaySeparated[$key] = (clone $baseQuery)
                    ->whereBetween('created_at', [$todayStart, $now])
                    ->count();

                $lastHourSeparated[$key] = (clone $baseQuery)
                    ->whereBetween('created_at', [$lastHourStart, $now])
                    ->count();
            }

            $todayTotal = array_sum($todaySeparated);
            $lastHourTotal = array_sum($lastHourSeparated);

            $otherTodaySeparated = [];
            foreach ($todaySeparated as $key => $count) {
                $otherTodaySeparated[$key] = max(0, $count - ($lastHourSeparated[$key] ?? 0));
            }

            $data = [
                'time_window' => [
                    'now' => $now,
                    'today_start' => $todayStart,
                    'last_hour_start' => $lastHourStart,
                ],
                'today_total_errors' => $todayTotal,
                'today_separated_error_count' => $todaySeparated,
                'last_hour_errors' => [
                    'total' => $lastHourTotal,
                    'separated' => $lastHourSeparated,
                ],
                'others_errors_today' => [
                    'total' => max(0, $todayTotal - $lastHourTotal),
                    'separated' => $otherTodaySeparated,
                ],
            ];

            return $this->success('Overall error report fetched successfully', $data);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function productCreateLogs(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'nullable|integer|exists:users,id',
                'level' => 'nullable|string|max:50',
                'search' => 'nullable|string|max:255',
                'from' => 'nullable|date',
                'to' => 'nullable|date|after_or_equal:from',
                'per_page' => 'nullable|integer|min:1|max:200',
            ]);

            $query = ProductCreateErrorLog::query()
                ->with('user:id,name,email')
                ->orderByDesc('id');

            if (!empty($validated['user_id'])) {
                $query->where('user_id', $validated['user_id']);
            }

            if (!empty($validated['level'])) {
                $query->where('level', $validated['level']);
            }

            if (!empty($validated['from'])) {
                $query->whereDate('created_at', '>=', $validated['from']);
            }

            if (!empty($validated['to'])) {
                $query->whereDate('created_at', '<=', $validated['to']);
            }

            if (!empty($validated['search'])) {
                $search = $validated['search'];
                $query->where(function ($q) use ($search) {
                    $q->where('message', 'like', "%{$search}%")
                        ->orWhere('file', 'like', "%{$search}%")
                        ->orWhere('url', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%");
                });
            }

            $logs = $query->paginate($validated['per_page'] ?? 20);

            return $this->success('Product create error logs fetched successfully', $logs);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function loginLogs(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'nullable|integer|exists:users,id',
                'level' => 'nullable|string|max:50',
                'search' => 'nullable|string|max:255',
                'from' => 'nullable|date',
                'to' => 'nullable|date|after_or_equal:from',
                'per_page' => 'nullable|integer|min:1|max:200',
            ]);

            $query = LoginError::query()
                ->with('user:id,name,email,phone')
                ->orderByDesc('id');

            if (!empty($validated['user_id'])) {
                $query->where('user_id', $validated['user_id']);
            }

            if (!empty($validated['level'])) {
                $query->where('level', $validated['level']);
            }

            if (!empty($validated['from'])) {
                $query->whereDate('created_at', '>=', $validated['from']);
            }

            if (!empty($validated['to'])) {
                $query->whereDate('created_at', '<=', $validated['to']);
            }

            if (!empty($validated['search'])) {
                $search = $validated['search'];
                $query->where(function ($q) use ($search) {
                    $q->where('message', 'like', "%{$search}%")
                        ->orWhere('file', 'like', "%{$search}%")
                        ->orWhere('url', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%");
                });
            }

            $logs = $query->paginate($validated['per_page'] ?? 20);

            return $this->success('Login error logs fetched successfully', $logs);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function loginSuccessLogs(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'nullable|integer|exists:users,id',
                'user_type' => 'nullable|string|max:50',
                'role' => 'nullable|string|max:50',
                'login_type' => 'nullable|string|max:50',
                'search' => 'nullable|string|max:255',
                'from' => 'nullable|date',
                'to' => 'nullable|date|after_or_equal:from',
                'per_page' => 'nullable|integer|min:1|max:200',
            ]);

            $query = LoginSuccessLog::query()
                ->with('user:id,name,email,phone,user_type,role')
                ->orderByDesc('id');

            if (!empty($validated['user_id'])) {
                $query->where('user_id', $validated['user_id']);
            }

            if (!empty($validated['user_type'])) {
                $query->where('user_type', $validated['user_type']);
            }

            if (!empty($validated['role'])) {
                $query->where('role', $validated['role']);
            }

            if (!empty($validated['login_type'])) {
                $query->where('login_type', $validated['login_type']);
            }

            if (!empty($validated['from'])) {
                $query->whereDate('logged_in_at', '>=', $validated['from']);
            }

            if (!empty($validated['to'])) {
                $query->whereDate('logged_in_at', '<=', $validated['to']);
            }

            if (!empty($validated['search'])) {
                $search = $validated['search'];
                $query->where(function ($q) use ($search) {
                    $q->where('url', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('user_agent', 'like', "%{$search}%")
                        ->orWhere('token_name', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                });
            }

            $logs = $query->paginate($validated['per_page'] ?? 20);

            return $this->success('Login success logs fetched successfully', $logs);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function loginSuccessReport(Request $request)
    {
        try {
            $validated = $request->validate([
                'from' => 'nullable|date',
                'to' => 'nullable|date|after_or_equal:from',
            ]);

            $baseQuery = LoginSuccessLog::query();

            if (!empty($validated['from'])) {
                $baseQuery->whereDate('logged_in_at', '>=', $validated['from']);
            }

            if (!empty($validated['to'])) {
                $baseQuery->whereDate('logged_in_at', '<=', $validated['to']);
            }

            $now = now();
            $todayStart = $now->copy()->startOfDay();
            $lastHourStart = $now->copy()->subHour();
            $last7Start = $now->copy()->subDays(6)->startOfDay();

            $total = (clone $baseQuery)->count();
            $today = (clone $baseQuery)->whereBetween('logged_in_at', [$todayStart, $now])->count();
            $lastHour = (clone $baseQuery)->whereBetween('logged_in_at', [$lastHourStart, $now])->count();
            $last7Days = (clone $baseQuery)->where('logged_in_at', '>=', $last7Start)->count();

            $byUserType = (clone $baseQuery)
                ->selectRaw("COALESCE(user_type, 'unknown') as user_type, COUNT(*) as total")
                ->groupBy('user_type')
                ->orderByDesc('total')
                ->get();

            $byRole = (clone $baseQuery)
                ->selectRaw("COALESCE(role, 'unknown') as role, COUNT(*) as total")
                ->groupBy('role')
                ->orderByDesc('total')
                ->get();

            $daily = [];
            for ($i = 0; $i < 7; $i++) {
                $date = $now->copy()->subDays($i)->toDateString();
                $daily[] = [
                    'date' => $date,
                    'total' => (clone $baseQuery)->whereDate('logged_in_at', $date)->count(),
                ];
            }

            return $this->success('Login success report fetched successfully', [
                'time_window' => [
                    'now' => $now,
                    'today_start' => $todayStart,
                    'last_hour_start' => $lastHourStart,
                    'last_7_days_start' => $last7Start,
                ],
                'total_success_logins' => $total,
                'today_success_logins' => $today,
                'last_hour_success_logins' => $lastHour,
                'last_7_days_success_logins' => $last7Days,
                'by_user_type' => $byUserType,
                'by_role' => $byRole,
                'last_7_days_breakdown' => $daily,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function registrationLogs(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'nullable|integer|exists:users,id',
                'level' => 'nullable|string|max:50',
                'search' => 'nullable|string|max:255',
                'from' => 'nullable|date',
                'to' => 'nullable|date|after_or_equal:from',
                'per_page' => 'nullable|integer|min:1|max:200',
            ]);

            $query = RegistrationErrorLog::query()
                ->with('user:id,name,email,phone')
                ->orderByDesc('id');

            if (!empty($validated['user_id'])) {
                $query->where('user_id', $validated['user_id']);
            }

            if (!empty($validated['level'])) {
                $query->where('level', $validated['level']);
            }

            if (!empty($validated['from'])) {
                $query->whereDate('created_at', '>=', $validated['from']);
            }

            if (!empty($validated['to'])) {
                $query->whereDate('created_at', '<=', $validated['to']);
            }

            if (!empty($validated['search'])) {
                $search = $validated['search'];
                $query->where(function ($q) use ($search) {
                    $q->where('message', 'like', "%{$search}%")
                        ->orWhere('file', 'like', "%{$search}%")
                        ->orWhere('url', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%");
                });
            }

            $logs = $query->paginate($validated['per_page'] ?? 20);

            return $this->success('Registration error logs fetched successfully', $logs);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function orderLogs(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'nullable|integer|exists:users,id',
                'level' => 'nullable|string|max:50',
                'search' => 'nullable|string|max:255',
                'from' => 'nullable|date',
                'to' => 'nullable|date|after_or_equal:from',
                'per_page' => 'nullable|integer|min:1|max:200',
            ]);

            $query = OrderErrorLog::query()
                ->with('user:id,name,email,phone')
                ->orderByDesc('id');

            if (!empty($validated['user_id'])) {
                $query->where('user_id', $validated['user_id']);
            }

            if (!empty($validated['level'])) {
                $query->where('level', $validated['level']);
            }

            if (!empty($validated['from'])) {
                $query->whereDate('created_at', '>=', $validated['from']);
            }

            if (!empty($validated['to'])) {
                $query->whereDate('created_at', '<=', $validated['to']);
            }

            if (!empty($validated['search'])) {
                $search = $validated['search'];
                $query->where(function ($q) use ($search) {
                    $q->where('message', 'like', "%{$search}%")
                        ->orWhere('file', 'like', "%{$search}%")
                        ->orWhere('url', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%");
                });
            }

            $logs = $query->paginate($validated['per_page'] ?? 20);

            return $this->success('Order error logs fetched successfully', $logs);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
