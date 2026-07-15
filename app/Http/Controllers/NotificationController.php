<?php

namespace App\Http\Controllers;


use Illuminate\Http\Request;
use App\Models\Notification;

class NotificationController extends Controller
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

	/**
	 * GET /notifications
	 * Returns all notifications (paginated)
	 */
	public function getAllNotification(Request $request)
	{
		try {
			$perPage = (int) ($request->get('per_page', 20));
			$notifications = Notification::latest()->paginate($perPage);

			return response()->json([
				'status' => 'success',
				'message' => 'Notifications fetched successfully',
				'data' => $notifications
			]);
		} catch (\Throwable $e) {
			return response()->json([
				'status' => 'failed',
				'message' => 'Something went wrong',
				'errors' => ['error' => $e->getMessage()]
			], 500);
		}
	}

	public function getNotificationByUser(Request $request, $userId)
	{
		try {
			$query = Notification::where('send_to', $userId);

			if ($request->filled('is_seen')) {
				$query->where('is_seen', filter_var($request->is_seen, FILTER_VALIDATE_BOOLEAN));
			}

			if ($request->filled('type')) {
				$query->where('type', $request->type);
			}

			if ($request->filled('module')) {
				$query->where('module', $request->module);
			}

			$baseCountQuery = Notification::where('send_to', $userId);
			$summary = [
				'total_count' => (clone $baseCountQuery)->count(),
				'unread_count' => (clone $baseCountQuery)->where('is_seen', false)->count(),
				'read_count' => (clone $baseCountQuery)->where('is_seen', true)->count(),
			];

			if ($request->filled('all') && (int) $request->get('all') === 1) {
				return $this->success('User notifications fetched successfully', [
					'notifications' => $query->latest()->get(),
					'summary' => $summary,
				]);
			}

			$perPage = (int) ($request->get('per_page', 20));

			return $this->success('User notifications fetched successfully', [
				'notifications' => $query->latest()->paginate($perPage),
				'summary' => $summary,
			]);
		} catch (\Throwable $e) {
			return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
		}
	}

	public function readUnread(Request $request, $id)
	{
		try {
			$validated = $request->validate([
				'is_seen' => ['required', 'boolean'],
				'user_id' => ['nullable', 'integer', 'exists:users,id'],
			]);

			$query = Notification::where('id', $id);

			if (!empty($validated['user_id'])) {
				$query->where('send_to', $validated['user_id']);
			}

			$notification = $query->first();

			if (!$notification) {
				return $this->failed('Notification not found', null, 404);
			}

			$notification->is_seen = (bool) $validated['is_seen'];
			$notification->save();

			$message = $notification->is_seen
				? 'Notification marked as read successfully'
				: 'Notification marked as unread successfully';

			return $this->success($message, $notification);
		} catch (\Illuminate\Validation\ValidationException $e) {
			return $this->failed('Validation failed', $e->errors(), 422);
		} catch (\Throwable $e) {
			return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
		}
	}

	public function markAllAsReadByUser(Request $request, $userId)
	{
		try {
			$updatedCount = Notification::where('send_to', $userId)
				->where('is_seen', false)
				->update(['is_seen' => true]);

			return $this->success('All notifications marked as read successfully', [
				'updated_count' => $updatedCount,
			]);
		} catch (\Throwable $e) {
			return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
		}
	}
}
