<?php

namespace App\Http\Controllers;

use App\Models\TaskType;
use App\Models\TaskStatus;
use Illuminate\Http\Request;

class TaskTypeController extends Controller
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

            $type = TaskType::create($validated);

            return $this->success('Task type created successfully', $type, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function list(Request $request)
    {
        try {
            $query = TaskType::query();

            if ($request->filled('is_active')) {
                $query->where('is_active', (bool) $request->is_active);
            }

            $query->latest();

            if ($request->filled('all') && (int) $request->get('all') === 1) {
                return $this->success('Task types fetched successfully', $query->get());
            }

            $perPage = (int) $request->get('per_page', 20);

            return $this->success('Task types fetched successfully', $query->paginate($perPage));
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
    public function statusList(Request $request)
    {
        try {
            $query = TaskStatus::query();

            if ($request->filled('is_active')) {
                $query->where('is_active', (bool) $request->is_active);
            }

            $query->latest();

            if ($request->filled('all') && (int) $request->get('all') === 1) {
                return $this->success('Task statuses fetched successfully', $query->get());
            }

            $perPage = (int) $request->get('per_page', 20);

            return $this->success('Task statuses fetched successfully', $query->paginate($perPage));
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function details($id)
    {
        try {
            $type = TaskType::find($id);

            if (!$type) {
                return $this->failed('Task type not found', null, 404);
            }

            return $this->success('Task type fetched successfully', $type);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $type = TaskType::find($id);

            if (!$type) {
                return $this->failed('Task type not found', null, 404);
            }

            $validated = $request->validate([
                'name' => ['nullable', 'string', 'max:100'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $type->fill($validated);
            $type->save();

            return $this->success('Task type updated successfully', $type);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function delete($id)
    {
        try {
            $type = TaskType::find($id);

            if (!$type) {
                return $this->failed('Task type not found', null, 404);
            }

            $type->delete();

            return $this->success('Task type deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
