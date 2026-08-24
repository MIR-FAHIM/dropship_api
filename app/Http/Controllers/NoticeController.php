<?php

namespace App\Http\Controllers;

use App\Models\Notice;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NoticeController extends Controller
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

    private function rules(bool $update = false): array
    {
        $required = $update ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:255'],
            'message' => [$required, 'string'],
            'audience_type' => ['sometimes', 'nullable', 'string', Rule::in(['reseller', 'supplier', 'vendor', 'admin', 'all'])],
            'notice_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['draft', 'published', 'inactive'])],
            'created_by' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'published_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:published_at'],
        ];
    }

    public function list(Request $request)
    {
        try {
            $query = Notice::with('creator:id,name,email,phone,user_type,role');

            if ($request->filled('audience_type')) {
                $query->where('audience_type', $request->audience_type);
            }

            if ($request->filled('notice_type')) {
                $query->where('notice_type', $request->notice_type);
            }

            if ($request->filled('priority')) {
                $query->where('priority', $request->priority);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('search')) {
                $search = trim($request->search);
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('message', 'like', "%{$search}%");
                });
            }

            if ($request->filled('active_only') && (int) $request->get('active_only') === 1) {
                $query->where('status', 'published')
                    ->where(function ($q) {
                        $q->whereNull('published_at')->orWhere('published_at', '<=', now());
                    })
                    ->where(function ($q) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                    });
            }

            $query->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")
                ->latest();

            if ($request->filled('all') && (int) $request->get('all') === 1) {
                return $this->success('Notices fetched successfully', $query->get());
            }

            $perPage = (int) $request->get('per_page', 20);

            return $this->success('Notices fetched successfully', $query->paginate($perPage));
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function resellerNotices(Request $request)
    {
        try {
            $query = Notice::whereIn('audience_type', ['reseller', 'all'])
                ->where('status', 'published')
                ->where(function ($q) {
                    $q->whereNull('published_at')->orWhere('published_at', '<=', now());
                })
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                })
                ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")
                ->latest();

            if ($request->filled('all') && (int) $request->get('all') === 1) {
                return $this->success('Reseller notices fetched successfully', $query->get());
            }

            $perPage = (int) $request->get('per_page', 20);

            return $this->success('Reseller notices fetched successfully', $query->paginate($perPage));
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function add(Request $request)
    {
        try {
            $validated = $request->validate($this->rules());

            $notice = Notice::create([
                'title' => $validated['title'],
                'message' => $validated['message'],
                'audience_type' => $validated['audience_type'] ?? 'reseller',
                'notice_type' => $validated['notice_type'] ?? 'general',
                'priority' => $validated['priority'] ?? 'normal',
                'status' => $validated['status'] ?? 'draft',
                'created_by' => $validated['created_by'] ?? null,
                'published_at' => $validated['published_at'] ?? null,
                'expires_at' => $validated['expires_at'] ?? null,
            ]);

            return $this->success('Notice created successfully', $notice, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function details($id)
    {
        try {
            $notice = Notice::with('creator:id,name,email,phone,user_type,role')->find($id);

            if (!$notice) {
                return $this->failed('Notice not found', null, 404);
            }

            return $this->success('Notice fetched successfully', $notice);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $notice = Notice::find($id);

            if (!$notice) {
                return $this->failed('Notice not found', null, 404);
            }

            $validated = $request->validate($this->rules(true));

            if (
                array_key_exists('expires_at', $validated)
                && !array_key_exists('published_at', $validated)
                && $notice->published_at
                && $validated['expires_at']
                && $notice->published_at->greaterThan($validated['expires_at'])
            ) {
                return $this->failed('Validation failed', [
                    'expires_at' => ['The expires at date must be after or equal to published at.'],
                ], 422);
            }

            $notice->update($validated);

            return $this->success('Notice updated successfully', $notice->fresh('creator:id,name,email,phone,user_type,role'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function delete($id)
    {
        try {
            $notice = Notice::find($id);

            if (!$notice) {
                return $this->failed('Notice not found', null, 404);
            }

            $notice->delete();

            return $this->success('Notice deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
