<?php

namespace App\Service;

use App\Events\WhatsAppMessageReceived;
use App\Models\User;
use App\Models\WhatsAppMessageLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppCloudService
{
    private bool $enabled;
    private ?string $accessToken;
    private ?string $phoneNumberId;
    private ?string $businessAccountId;
    private string $apiVersion;
    private ?string $verifyToken;

    public function __construct()
    {
        $this->enabled = (bool) config('services.whatsapp.enabled', false);
        $this->accessToken = config('services.whatsapp.access_token');
        $this->phoneNumberId = config('services.whatsapp.phone_number_id');
        $this->businessAccountId = config('services.whatsapp.business_account_id');
        $this->apiVersion = rtrim((string) config('services.whatsapp.api_version', 'v22.0'), '/');
        $this->verifyToken = config('services.whatsapp.webhook_verify_token');
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isConfigured(): bool
    {
        return $this->enabled && !empty($this->accessToken) && !empty($this->phoneNumberId);
    }

    /**
     * Normalize a phone number to standard international E.164 digits without leading '+'.
     */
    public function normalizePhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if (!$digits) {
            return null;
        }

        if (str_starts_with($digits, '880')) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '88' . $digits;
        }

        if (strlen($digits) === 10) {
            return '880' . $digits;
        }

        return $digits;
    }

    private function getEndpoint(): string
    {
        return "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
    }

    /**
     * Send an approved Meta WhatsApp template message.
     */
    public function sendTemplateMessage(
        string $to,
        string $templateName,
        string $languageCode = 'bn',
        array $bodyParameters = [],
        ?int $userId = null,
        ?string $notificationType = 'dropshipper_activation'
    ): array {
        $normalizedRecipient = $this->normalizePhone($to);
        $cleanTemplate = trim($templateName);
        $cleanLanguage = trim($languageCode);

        if (!$normalizedRecipient) {
            return [
                'success' => false,
                'status' => 422,
                'message_id' => null,
                'error' => ['message' => 'Invalid or missing recipient phone number.'],
            ];
        }

        if (!$this->isConfigured()) {
            Log::info('WhatsApp message skipped: service is disabled or credentials missing.', [
                'recipient' => $normalizedRecipient,
                'template' => $cleanTemplate,
            ]);

            return [
                'success' => false,
                'status' => 503,
                'message_id' => null,
                'error' => ['message' => 'WhatsApp service is disabled or unconfigured.'],
            ];
        }

        $parametersPayload = array_map(function ($param) {
            return [
                'type' => 'text',
                'text' => (string) $param,
            ];
        }, $bodyParameters);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $normalizedRecipient,
            'type' => 'template',
            'template' => [
                'name' => $cleanTemplate,
                'language' => [
                    'code' => $cleanLanguage,
                ],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => $parametersPayload,
                    ],
                ],
            ],
        ];

        $log = WhatsAppMessageLog::create([
            'direction' => 'outbound',
            'user_id' => $userId,
            'phone_number' => $normalizedRecipient,
            'message_type' => 'template',
            'template_name' => $cleanTemplate,
            'template_language' => $cleanLanguage,
            'parameters' => $bodyParameters,
            'status' => 'processing',
            'raw_payload' => ['request' => $payload],
        ]);

        try {
            $response = Http::timeout(15)
                ->withToken($this->accessToken)
                ->acceptJson()
                ->post($this->getEndpoint(), $payload);

            $responseBody = $response->json() ?? [];
            $wamid = Arr::get($responseBody, 'messages.0.id');

            if ($response->successful() && $wamid) {
                $log->markAsSent($wamid, [
                    'request' => $payload,
                    'response' => $responseBody,
                ]);

                return [
                    'success' => true,
                    'status' => $response->status(),
                    'message_id' => $wamid,
                    'data' => $responseBody,
                    'log_id' => $log->id,
                ];
            }

            $errorCode = (string) Arr::get($responseBody, 'error.code', $response->status());
            $errorMessage = Arr::get($responseBody, 'error.message', 'Meta WhatsApp API returned an error.');

            $log->markAsFailed($errorCode, $errorMessage, [
                'request' => $payload,
                'response' => $responseBody,
            ]);

            Log::warning('WhatsApp Cloud API template message failed', [
                'recipient' => $normalizedRecipient,
                'template' => $cleanTemplate,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);

            return [
                'success' => false,
                'status' => $response->status(),
                'message_id' => null,
                'error' => $responseBody['error'] ?? ['message' => $errorMessage],
                'log_id' => $log->id,
            ];
        } catch (\Throwable $e) {
            $log->markAsFailed('EXCEPTION', $e->getMessage(), ['request' => $payload]);

            Log::error('WhatsApp Cloud API exception during template send: ' . $e->getMessage(), [
                'recipient' => $normalizedRecipient,
                'template' => $cleanTemplate,
            ]);

            return [
                'success' => false,
                'status' => 500,
                'message_id' => null,
                'error' => ['message' => 'Network or service exception: ' . $e->getMessage()],
                'log_id' => $log->id,
            ];
        }
    }

    /**
     * Send a free-form WhatsApp text message (e.g. replies to product queries or support within 24h window).
     */
    public function sendTextMessage(
        string $to,
        string $text,
        ?string $replyToMessageId = null,
        ?int $userId = null
    ): array {
        $normalizedRecipient = $this->normalizePhone($to);

        if (!$normalizedRecipient) {
            return [
                'success' => false,
                'status' => 422,
                'message_id' => null,
                'error' => ['message' => 'Invalid or missing recipient phone number.'],
            ];
        }

        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'status' => 503,
                'message_id' => null,
                'error' => ['message' => 'WhatsApp service is disabled or unconfigured.'],
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $normalizedRecipient,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $text,
            ],
        ];

        if ($replyToMessageId) {
            $payload['context'] = ['message_id' => $replyToMessageId];
        }

        $log = WhatsAppMessageLog::create([
            'direction' => 'outbound',
            'user_id' => $userId,
            'phone_number' => $normalizedRecipient,
            'message_type' => 'text',
            'message_content' => $text,
            'status' => 'processing',
            'raw_payload' => ['request' => $payload],
        ]);

        try {
            $response = Http::timeout(15)
                ->withToken($this->accessToken)
                ->acceptJson()
                ->post($this->getEndpoint(), $payload);

            $responseBody = $response->json() ?? [];
            $wamid = Arr::get($responseBody, 'messages.0.id');

            if ($response->successful() && $wamid) {
                $log->markAsSent($wamid, [
                    'request' => $payload,
                    'response' => $responseBody,
                ]);

                return [
                    'success' => true,
                    'status' => $response->status(),
                    'message_id' => $wamid,
                    'data' => $responseBody,
                    'log_id' => $log->id,
                ];
            }

            $errorCode = (string) Arr::get($responseBody, 'error.code', $response->status());
            $errorMessage = Arr::get($responseBody, 'error.message', 'Meta WhatsApp API returned an error.');

            $log->markAsFailed($errorCode, $errorMessage, [
                'request' => $payload,
                'response' => $responseBody,
            ]);

            return [
                'success' => false,
                'status' => $response->status(),
                'message_id' => null,
                'error' => $responseBody['error'] ?? ['message' => $errorMessage],
                'log_id' => $log->id,
            ];
        } catch (\Throwable $e) {
            $log->markAsFailed('EXCEPTION', $e->getMessage(), ['request' => $payload]);

            return [
                'success' => false,
                'status' => 500,
                'message_id' => null,
                'error' => ['message' => $e->getMessage()],
                'log_id' => $log->id,
            ];
        }
    }

    /**
     * Verify Meta Webhook handshake challenge.
     */
    public function verifyWebhook(?string $mode, ?string $token, ?string $challenge): ?string
    {
        if ($mode === 'subscribe' && !empty($this->verifyToken) && $token === $this->verifyToken) {
            return $challenge;
        }

        return null;
    }

    /**
     * Process Meta Webhook notification payload (delivery receipts & inbound messages).
     */
    public function processWebhookPayload(array $payload): array
    {
        $entries = Arr::get($payload, 'entry', []);
        $processedStatuses = 0;
        $processedMessages = 0;

        foreach ($entries as $entry) {
            $changes = Arr::get($entry, 'changes', []);
            foreach ($changes as $change) {
                $value = Arr::get($change, 'value', []);

                // 1. Process Message Status Updates (sent, delivered, read, failed)
                $statuses = Arr::get($value, 'statuses', []);
                foreach ($statuses as $statusItem) {
                    $wamid = Arr::get($statusItem, 'id');
                    $status = Arr::get($statusItem, 'status');

                    if (!$wamid || !$status) {
                        continue;
                    }

                    $log = WhatsAppMessageLog::where('provider_message_id', $wamid)->first();
                    if ($log) {
                        match ($status) {
                            'delivered' => $log->markAsDelivered(['status_update' => $statusItem]),
                            'read' => $log->markAsRead(['status_update' => $statusItem]),
                            'failed' => $log->markAsFailed(
                                (string) Arr::get($statusItem, 'errors.0.code', 'FAILED'),
                                Arr::get($statusItem, 'errors.0.title', 'Delivery failed'),
                                ['status_update' => $statusItem]
                            ),
                            default => null,
                        };
                        $processedStatuses++;
                    }
                }

                // 2. Process Inbound Messages (e.g. dropshipper product query or reply)
                $messages = Arr::get($value, 'messages', []);
                foreach ($messages as $msg) {
                    $from = Arr::get($msg, 'from');
                    $wamid = Arr::get($msg, 'id');
                    $type = Arr::get($msg, 'type');
                    $body = Arr::get($msg, 'text.body');

                    if (!$from || !$wamid) {
                        continue;
                    }

                    // Prevent duplicate processing of the same inbound wamid
                    $existing = WhatsAppMessageLog::where('provider_message_id', $wamid)->first();
                    if ($existing) {
                        continue;
                    }

                    // Locate registered user by phone number
                    $normalizedFrom = $this->normalizePhone($from);
                    $suffix = substr($normalizedFrom ?? $from, -10);

                    $user = User::where('phone', $normalizedFrom)
                        ->orWhere('phone', $from)
                        ->orWhere('phone', 'like', "%{$suffix}")
                        ->first();

                    $log = WhatsAppMessageLog::create([
                        'direction' => 'inbound',
                        'user_id' => $user?->id,
                        'phone_number' => $normalizedFrom ?? $from,
                        'message_type' => $type ?? 'text',
                        'message_content' => $body,
                        'provider_message_id' => $wamid,
                        'status' => 'received',
                        'raw_payload' => $msg,
                    ]);

                    // Dispatch event for other modules (e.g. product inquiries, customer support)
                    event(new WhatsAppMessageReceived(
                        senderPhone: $normalizedFrom ?? $from,
                        messageText: $body ?? '',
                        providerMessageId: $wamid,
                        rawMessage: $msg,
                        user: $user,
                        log: $log
                    ));

                    $processedMessages++;
                }
            }
        }

        return [
            'success' => true,
            'processed_statuses' => $processedStatuses,
            'processed_messages' => $processedMessages,
        ];
    }
}
