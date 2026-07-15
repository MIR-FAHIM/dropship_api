<?php

namespace App\Service;

use App\Models\Notification;
use App\Models\User;

class NotificationService
{
	/**
	 * Create a new notification.
	 *
	 * @param array $data
	 * @return Notification
	 */
	public function createNotification(array $data): Notification
	{
		// Only allow fillable fields
		$notification = Notification::create([
			'title'      => $data['title'] ?? '',
			'subtitle'   => $data['subtitle'] ?? null,
			'created_by' => $data['created_by'] ?? null,
			'send_to'    => $data['send_to'] ?? null,
			'is_seen'    => $data['is_seen'] ?? false,
			'type'       => $data['type'] ?? null,
			'is_active'  => $data['is_active'] ?? true,
			'image'      => $data['image'] ?? null,
			'module'     => $data['module'] ?? null,
		]);
		return $notification;
	}

	public function createNotificationSafely(array $data): ?Notification
	{
		try {
			return $this->createNotification($data);
		} catch (\Throwable $e) {
			return null;
		}
	}

	public function createAdminNotifications(array $data): void
	{
		try {
			User::where(function ($query) {
				$query->where('role', 'admin')
					->orWhere('user_type', 'admin');
			})
				->get(['id'])
				->each(function (User $admin) use ($data) {
					$this->createNotificationSafely(array_merge($data, [
						'send_to' => $admin->id,
					]));
				});
		} catch (\Throwable $e) {
			// Notification failure should not block the primary business action.
		}
	}
}
