<?php

namespace App\Service;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MuthobartaSmsService
{
    public function send(string $receiver, string $message, string $type = 'notification', bool $removeDuplicate = true): array
    {
        $apiKey = config('services.muthobarta.api_key');
        $baseUrl = rtrim((string) config('services.muthobarta.base_url'), '/');
        $senderId = config('services.muthobarta.sender_id');

        if (!$apiKey || !$baseUrl || !$senderId || !$receiver || !$message) {
            Log::warning('Muthobarta SMS skipped because configuration or payload is missing', [
                'receiver' => $receiver,
                'type' => $type,
                'has_api_key' => (bool) $apiKey,
                'has_base_url' => (bool) $baseUrl,
                'has_sender_id' => (bool) $senderId,
                'has_message' => (bool) $message,
            ]);

            return [
                'success' => false,
                'status' => 500,
                'body' => [
                    'message' => 'Muthobarta SMS configuration or payload is missing',
                ],
            ];
        }

        $payload = [
            'receiver' => $receiver,
            'message' => $message,
            'sender_id' => $senderId,
            'type' => $type,
            'remove_duplicate' => $removeDuplicate,
        ];

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => $apiKey,
                    'Accept' => 'application/json',
                ])
                ->post($baseUrl . '/send-sms', $payload);

            if ($response->failed()) {
                Log::warning('Muthobarta SMS provider request failed', [
                    'receiver' => $receiver,
                    'type' => $type,
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ]);
            }

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        } catch (\Throwable $e) {
            Log::warning('Muthobarta SMS send failed', [
                'receiver' => $receiver,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 500,
                'body' => [
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }
}
