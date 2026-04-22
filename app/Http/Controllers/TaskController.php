<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskImage;
use App\Models\AssignedTask;
use Illuminate\Http\Request;

class TaskController extends Controller



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
                'task_title' => ['required', 'string', 'max:255'],
                'task_details' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
                'priority_id' => ['nullable', 'integer', 'exists:task_priorities,id'],
                'task_type_id' => ['nullable', 'integer', 'exists:task_types,id'],
                'is_remind' => ['nullable', 'boolean'],
                'is_waiting' => ['nullable', 'boolean'],
                'due_date' => ['nullable', 'date'],
                'start_date' => ['nullable', 'date'],
                'project_id' => ['nullable', 'integer'],
                'project_phase_id' => ['nullable', 'integer'],
                'prospect_id' => ['nullable', 'integer'],
                'created_by' => ['nullable', 'integer', 'exists:users,id'],
                'status_id' => ['nullable', 'integer'],
                'department_id' => ['nullable', 'integer'],
                'completion_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'show_completion_percentage' => ['nullable', 'boolean'],
            ]);

            $task = Task::create($validated);
            $task->load(['priority', 'taskType', 'creator', 'assignedTo', 'taskImages']);

            return $this->success('Task created successfully', $task, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function list(Request $request)
    {
        try {
            $query = Task::with(['priority', 'taskType', 'status', 'creator', 'assignedTo.assignTo', 'taskImages.taskImage']);

            if ($request->filled('is_active')) {
                $query->where('is_active', (bool) $request->is_active);
            }

            if ($request->filled('priority_id')) {
                $query->where('priority_id', $request->priority_id);
            }

            if ($request->filled('task_type_id')) {
                $query->where('task_type_id', $request->task_type_id);
            }

            if ($request->filled('status_id')) {
                $query->where('status_id', $request->status_id);
            }

            if ($request->filled('created_by')) {
                $query->where('created_by', $request->created_by);
            }

            $query->latest();

            if ($request->filled('all') && (int) $request->get('all') === 1) {
                return $this->success('Tasks fetched successfully', $query->get());
            }

            $perPage = (int) $request->get('per_page', 20);

            return $this->success('Tasks fetched successfully', $query->paginate($perPage));
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function details($id)
    {
        try {
            $task = Task::with(['priority', 'taskType', 'creator', 'assignedTo.assignTo', 'taskImages.taskImage'])->find($id);

            if (!$task) {
                return $this->failed('Task not found', null, 404);
            }

            return $this->success('Task fetched successfully', $task);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $task = Task::find($id);

            if (!$task) {
                return $this->failed('Task not found', null, 404);
            }

            $validated = $request->validate([
                'task_title' => ['nullable', 'string', 'max:255'],
                'task_details' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
                'priority_id' => ['nullable', 'integer', 'exists:task_priorities,id'],
                'task_type_id' => ['nullable', 'integer', 'exists:task_types,id'],
                'is_remind' => ['nullable', 'boolean'],
                'is_waiting' => ['nullable', 'boolean'],
                'due_date' => ['nullable', 'date'],
                'start_date' => ['nullable', 'date'],
                'project_id' => ['nullable', 'integer'],
                'project_phase_id' => ['nullable', 'integer'],
                'prospect_id' => ['nullable', 'integer'],
                'created_by' => ['nullable', 'integer', 'exists:users,id'],
                'status_id' => ['nullable', 'integer'],
                'department_id' => ['nullable', 'integer'],
                'completion_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'show_completion_percentage' => ['nullable', 'boolean'],
            ]);

            $task->fill($validated);
            $task->save();
            $task->load(['priority', 'taskType', 'creator', 'assignedTo', 'taskImages']);

            return $this->success('Task updated successfully', $task);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function delete($id)
    {
        try {
            $task = Task::find($id);

            if (!$task) {
                return $this->failed('Task not found', null, 404);
            }

            $task->delete();

            return $this->success('Task deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        try {
            $task = Task::find($id);

            if (!$task) {
                return $this->failed('Task not found', null, 404);
            }

            $validated = $request->validate([
                'status_id' => ['required', 'integer', 'exists:task_statuses,id'],
            ]);

            $task->status_id = $validated['status_id'];
            $task->save();
            $task->load(['priority', 'taskType', 'status', 'creator', 'assignedTo', 'taskImages']);

            return $this->success('Task status updated successfully', $task);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Add an image to a task.
     */
    public function addTaskImage(Request $request)
    {
        try {
            $validated = $request->validate([
                'task_id' => ['required', 'integer', 'exists:tasks,id'],
                'task_image_id' => ['nullable', 'integer'],
                'type' => ['nullable', 'string', 'max:255'],
                'is_active' => ['nullable', 'boolean'],
                // 'image' => ['required', 'image', 'max:2048'], // Uncomment if handling file uploads
            ]);

            $taskImage = new TaskImage($validated);
            $taskImage->added_by = $request->user()->id ?? $request->input('added_by');
            $taskImage->save();

            // If handling file uploads, add logic here

            return $this->success('Task image added successfully', $taskImage, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Assign a task to a user.
     */
    public function assignTask(Request $request)
    {
        try {
            $validated = $request->validate([
                'task_id' => ['required', 'integer', 'exists:tasks,id'],
                'assign_to' => ['required', 'integer', 'exists:users,id'],
                'note' => ['nullable', 'string'],
            ]);

            $assignedTask = new AssignedTask($validated);
            $assignedTask->added_by = $request->user()->id ?? $request->input('added_by');
            $assignedTask->save();

            $assignedTask->load(['task', 'addedBy', 'assignTo']);

            return $this->success('Task assigned successfully', $assignedTask, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
