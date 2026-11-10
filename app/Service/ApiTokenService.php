<?php

namespace App\Service;

use Illuminate\Support\Str;
use App\Models\User;
use App\Models\ApiToken;

class ApiTokenService
{
    public static function resolveUser(?string $plain): ?User
    {
        if (!$plain) {
            return null;
        }

        $token = ApiToken::with('user')->where('token_hash', hash('sha256', $plain))->first();

        return $token?->isValid() ? $token->user : null;
    }

    /**
     * Create a new API token for a user.
     * Returns array: ['plain' => string, 'token' => ApiToken]
     */
    public static function create(User $user, array $scopes = [], $days = 30, $name = null)
    {
        $plain = Str::random(64);

        $apiToken = ApiToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'scopes' => $scopes,
            'expires_at' => now()->addDays($days),
            'name' => $name,
        ]);

        return ['plain' => $plain, 'token' => $apiToken]; // return ONCE
    }
}
