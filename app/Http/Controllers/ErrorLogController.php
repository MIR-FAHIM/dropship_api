<?php

namespace App\Http\Controllers;

use App\Models\LoginError;
use App\Models\ProductCreateErrorLog;
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
}
