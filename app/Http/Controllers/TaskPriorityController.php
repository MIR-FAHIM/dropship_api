<?php

namespace App\Http\Controllers;

use App\Models\TaskPriority;
use Illuminate\Http\Request;

class TaskPriorityController extends Controller
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

    public function create(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $priority = TaskPriority::create($validated);

            return $this->success('Task priority created successfully', $priority, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function list(Request $request)
    {
        try {
            $query = TaskPriority::query();

            if ($request->filled('is_active')) {
                $query->where('is_active', (bool) $request->is_active);
            }

            $query->latest();

            if ($request->filled('all') && (int) $request->get('all') === 1) {
                return $this->success('Task priorities fetched successfully', $query->get());
            }

            $perPage = (int) $request->get('per_page', 20);

            return $this->success('Task priorities fetched successfully', $query->paginate($perPage));
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function details($id)
    {
        try {
            $priority = TaskPriority::find($id);

            if (!$priority) {
                return $this->failed('Task priority not found', null, 404);
            }

            return $this->success('Task priority fetched successfully', $priority);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $priority = TaskPriority::find($id);

            if (!$priority) {
                return $this->failed('Task priority not found', null, 404);
            }

            $validated = $request->validate([
                'name' => ['nullable', 'string', 'max:100'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $priority->fill($validated);
            $priority->save();

            return $this->success('Task priority updated successfully', $priority);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function delete($id)
    {
        try {
            $priority = TaskPriority::find($id);

            if (!$priority) {
                return $this->failed('Task priority not found', null, 404);
            }

            $priority->delete();

            return $this->success('Task priority deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
