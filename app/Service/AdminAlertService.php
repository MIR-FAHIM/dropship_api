<?php

namespace App\Service;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Tell every admin about an important dropshipper action: an in-app notification
 * (bell list) plus an FCM push to each admin with a device token. Same recipients
 * and delivery as the checkout "New checkout order" alert in OrderController.
 *
 * Never throws: call it after the main work is saved, so a notification or FCM
 * failure can't undo the action.
 */
class AdminAlertService
{
    public function __construct(
        private NotificationService $notificationService,
        private FirebasePushNotificationService $pushService,
    ) {
    }

    /**
     * @param string $title    short, e.g. "New support ticket"
     * @param string $subtitle a few words of detail (stored max 255 chars)
     * @param array  $data     FCM data payload, e.g. ['type' => 'support_ticket', 'ticket_id' => '12']
     */
    public function send(
        string $title,
        string $subtitle,
        array $data = [],
        ?int $createdBy = null,
        string $type = 'general',
        string $module = 'general',
    ): void {
        $subtitle = Str::limit($subtitle, 250, '…');

        try {
            $this->notificationService->createAdminNotifications([
                'title' => $title,
                'subtitle' => $subtitle,
                'created_by' => $createdBy,
                'is_seen' => false,
                'type' => $type,
                'is_active' => true,
                'image' => null,
                'module' => $module,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Admin in-app notification failed.', ['title' => $title, 'exception' => $e->getMessage()]);
        }

        try {
            User::query()
                ->where(fn ($q) => $q->where('role', 'admin')->orWhere('user_type', 'admin'))
                ->whereNotNull('device_token')
                ->where('device_token', '!=', '')
                ->get()
                ->each(fn (User $admin) => $this->pushService->sendToUser($admin, $title, $subtitle, $data));
        } catch (\Throwable $e) {
            Log::warning('Admin push notification failed.', ['title' => $title, 'exception' => $e->getMessage()]);
        }
    }
}
