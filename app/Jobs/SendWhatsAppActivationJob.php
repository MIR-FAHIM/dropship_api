<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WhatsAppMessageLog;
use App\Service\WhatsAppCloudService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWhatsAppActivationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly User $user)
    {
    }

    public function handle(WhatsAppCloudService $whatsAppService): void
    {
        // 1. Verify user exists and is active (unbanned)
        $user = $this->user->fresh();
        if (!$user || (int) $user->banned !== 0) {
            Log::info('WhatsApp activation notification skipped: user not found or not active.', [
                'user_id' => $this->user->id,
            ]);
            return;
        }

        // 2. Consent and Opt-out verification
        if ($user->whatsapp_consent === false || $user->whatsapp_opted_out_at !== null) {
            Log::info('WhatsApp activation notification skipped: recipient opted out or no consent.', [
                'user_id' => $user->id,
            ]);
            return;
        }

        // 3. Recipient phone number verification
        $rawPhone = $user->phone ?? $user->mobile ?? null;
        $normalizedPhone = $whatsAppService->normalizePhone($rawPhone);
        if (!$normalizedPhone) {
            Log::info('WhatsApp activation notification skipped: missing or invalid phone number.', [
                'user_id' => $user->id,
            ]);
            return;
        }

        // 4. Idempotency Check: Prevent duplicate activation messages
        $templateName = trim((string) config('services.whatsapp.activation_template', 'dropshipper_activation'));
        $alreadySent = WhatsAppMessageLog::where('user_id', $user->id)
            ->where('template_name', $templateName)
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->exists();

        if ($alreadySent) {
            Log::info('WhatsApp activation notification skipped: already sent previously.', [
                'user_id' => $user->id,
                'phone' => $normalizedPhone,
            ]);
            return;
        }

        // 5. Check if service is enabled and configured
        if (!$whatsAppService->isConfigured()) {
            Log::info('WhatsApp activation notification skipped: service disabled or unconfigured.', [
                'user_id' => $user->id,
            ]);
            return;
        }

        // 6. Build template variables
        $name = trim((string) ($user->name ?? 'Dropshipper'));
        $loginUrl = config('services.whatsapp.login_url', 'https://resellerbrain.com/login');
        $languageCode = trim((string) config('services.whatsapp.template_language', 'bn'));

        $parameters = [
            $name !== '' ? $name : 'Reseller',
            $loginUrl,
        ];

        // 7. Send message through WhatsApp Cloud Service
        $result = $whatsAppService->sendTemplateMessage(
            to: $normalizedPhone,
            templateName: $templateName,
            languageCode: $languageCode,
            bodyParameters: $parameters,
            userId: $user->id,
            notificationType: 'dropshipper_activation'
        );

        // 8. Error and retry handling
        if (!$result['success']) {
            $status = $result['status'] ?? 500;

            // Permanent errors (4xx client errors like bad request, invalid template, unauthorized) should NOT retry indefinitely
            if ($status >= 400 && $status < 500) {
                Log::warning('WhatsApp activation notification permanent failure (not retrying):', [
                    'user_id' => $user->id,
                    'status' => $status,
                    'error' => $result['error'] ?? null,
                ]);
                return;
            }

            // Transient server errors (5xx, network timeout) can be retried by the queue worker
            throw new \RuntimeException(
                'WhatsApp Cloud API temporary failure (status ' . $status . '): ' .
                json_encode($result['error'] ?? 'Unknown network error')
            );
        }

        Log::info('WhatsApp activation notification sent successfully.', [
            'user_id' => $user->id,
            'message_id' => $result['message_id'],
        ]);
    }
}
