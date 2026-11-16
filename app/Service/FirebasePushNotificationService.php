<?php

namespace App\Service;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FirebasePushNotificationService
{
    private const MESSAGING_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * Send a Firebase Cloud Messaging notification to a user's saved device token.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): array
    {
        $deviceToken = trim((string) $user->device_token);

        if ($deviceToken === '') {
            return [
                'success' => false,
                'skipped' => true,
                'reason' => 'missing_device_token',
            ];
        }

        try {
            $projectId = trim((string) config('services.firebase.project_id'));
            $credentials = $this->loadCredentials();

            if ($projectId === '' || empty($credentials['client_email']) || empty($credentials['private_key'])) {
                return $this->failure('configuration_missing', 'Firebase project ID or service account credentials are not configured.');
            }

            $accessToken = $this->getAccessToken($credentials);
            $message = [
                'token' => $deviceToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $this->stringifyData($data),
                'android' => [
                    'priority' => 'HIGH',
                ],
                'apns' => [
                    'headers' => ['apns-priority' => '10'],
                    'payload' => ['aps' => ['sound' => 'default']],
                ],
            ];

            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->timeout(20)
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                    'message' => $message,
                ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message_id' => $response->json('name'),
                ];
            }

            $responseBody = $response->json();
            $errorCode = (string) data_get($responseBody, 'error.status', 'FCM_ERROR');
            $errorMessage = (string) data_get($responseBody, 'error.message', 'Firebase rejected the push notification.');

            if ($this->isUnregisteredToken($responseBody)) {
                $user->forceFill(['device_token' => null])->saveQuietly();
            }

            Log::warning('Firebase push notification was rejected.', [
                'user_id' => $user->id,
                'http_status' => $response->status(),
                'error_code' => $errorCode,
            ]);

            return $this->failure($errorCode, $errorMessage, $response->status());
        } catch (Throwable $exception) {
            Log::warning('Firebase push notification could not be sent.', [
                'user_id' => $user->id,
                'exception' => $exception->getMessage(),
            ]);

            return $this->failure('firebase_request_failed', 'Firebase notification could not be sent. Check Firebase configuration and server logs.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCredentials(): array
    {
        $path = trim((string) config('services.firebase.credentials'));

        if ($path === '') {
            throw new \RuntimeException('Firebase service account credential path is not configured.');
        }

        if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:/', $path)) {
            $path = base_path($path);
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Firebase service account credential file is missing or unreadable.');
        }

        $credentials = json_decode((string) file_get_contents($path), true);

        if (!is_array($credentials)) {
            throw new \RuntimeException('Firebase service account credential file is not valid JSON.');
        }

        return $credentials;
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function getAccessToken(array $credentials): string
    {
        $clientEmail = (string) $credentials['client_email'];
        $cacheKey = 'firebase_fcm_access_token_' . sha1($clientEmail);
        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        $tokenUri = (string) ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        $now = time();
        $jwtHeader = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $jwtClaims = $this->base64UrlEncode(json_encode([
            'iss' => $clientEmail,
            'scope' => self::MESSAGING_SCOPE,
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsignedJwt = $jwtHeader . '.' . $jwtClaims;

        $privateKey = openssl_pkey_get_private((string) $credentials['private_key']);
        if ($privateKey === false) {
            throw new \RuntimeException('Firebase service account private key could not be loaded.');
        }

        $signature = '';
        $signed = openssl_sign($unsignedJwt, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (!$signed) {
            throw new \RuntimeException('Firebase OAuth assertion could not be signed.');
        }

        $assertion = $unsignedJwt . '.' . $this->base64UrlEncode($signature);
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(15)
            ->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        if (!$response->successful() || !$response->json('access_token')) {
            throw new \RuntimeException('Google OAuth did not return an access token (HTTP ' . $response->status() . ').');
        }

        $accessToken = (string) $response->json('access_token');
        $expiresIn = max(60, (int) $response->json('expires_in', 3600) - 60);
        Cache::put($cacheKey, $accessToken, now()->addSeconds($expiresIn));

        return $accessToken;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * FCM's data payload only accepts string values.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function stringifyData(array $data): array
    {
        $stringData = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            $stringData[(string) $key] = is_scalar($value)
                ? (string) $value
                : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $stringData;
    }

    private function isUnregisteredToken(mixed $responseBody): bool
    {
        if (data_get($responseBody, 'error.status') === 'UNREGISTERED') {
            return true;
        }

        foreach ((array) data_get($responseBody, 'error.details', []) as $detail) {
            if (data_get($detail, 'errorCode') === 'UNREGISTERED') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $code, string $message, ?int $httpStatus = null): array
    {
        return [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'http_status' => $httpStatus,
            ],
        ];
    }
}
