<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\ApiToken;
use App\Service\ApiTokenService;

class AuthController extends Controller
{
    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
            'errors' => $errors
        ], $code);
    }

    /**
     * POST /auth/login
     */
    public function login(Request $request)
    {
        try {
            $validated = $request->validate([
                'email' => ['nullable', 'email', 'required_without:phone'],
                'phone' => ['nullable', 'string', 'required_without:email'],
                'password' => ['required', 'string', 'min:6'],
                'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
                'name' => ['nullable', 'string', 'max:255'],
            ]);

            $user = null;

            if (!empty($validated['email'])) {
                $user = User::where('email', $validated['email'])->first();
            } elseif (!empty($validated['phone'])) {
                $rawPhone = trim($validated['phone']);
                $normalizedPhone = preg_replace('/^\+?88/', '', $rawPhone);

                $user = User::where(function ($query) use ($rawPhone, $normalizedPhone) {
                    $query->where('phone', $rawPhone)
                        ->orWhere('phone', $normalizedPhone);
                })->first();
            }

            if (!$user || !Hash::check($validated['password'], $user->password)) {
                return $this->failed('Invalid credentials', null, 401);
            }

            $scopes = ['basic'];
            if ($user->role === 'admin') {
                $scopes[] = 'admin';
            }

            $days = $validated['expires_in_days'] ?? 30;
            $name = $validated['name'] ?? 'login-token';

            $created = ApiTokenService::create($user, $scopes, $days, $name);

            return $this->success('Login successful', [
                'token' => $created['plain'],
                'token_type' => 'Bearer',
                'expires_at' => $created['token']->expires_at,
                'token_id' => $created['token']->id,
                'user' => $user,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /auth/logout
     */
    public function logout(Request $request)
    {
        try {
            $apiToken = $request->attributes->get('api_token');

            if (!$apiToken) {
                return $this->failed('API token missing', null, 401);
            }

            $apiToken->update(['is_revoked' => true]);

            return $this->success('Logged out successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /auth/tokens
     * List all tokens for the authenticated user
     */
    public function listTokens(Request $request)
    {
        try {
            $user = $request->attributes->get('api_user');

            if (!$user) {
                return $this->failed('Not authenticated', null, 401);
            }

            $tokens = ApiToken::where('user_id', $user->id)->get();

            return $this->success('Tokens fetched', $tokens);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /auth/tokens/{id}
     * Revoke a token by id (must belong to the authenticated user)
     */
    public function revokeToken(Request $request, $id)
    {
        try {
            $user = $request->attributes->get('api_user');

            if (!$user) {
                return $this->failed('Not authenticated', null, 401);
            }

            $token = ApiToken::find($id);

            if (!$token) {
                return $this->failed('Token not found', null, 404);
            }

            if ($token->user_id !== $user->id) {
                return $this->failed('Forbidden', null, 403);
            }

            $token->update(['is_revoked' => true]);

            return $this->success('Token revoked');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
