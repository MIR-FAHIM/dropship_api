<?php

namespace App\Service;

use App\Models\Notification;

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
}
