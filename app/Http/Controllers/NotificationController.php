<?php

namespace App\Http\Controllers;


use Illuminate\Http\Request;
use App\Models\Notification;

class NotificationController extends Controller
{
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
}
