<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\ApiToken;
use App\Models\LoginError;
use App\Models\LoginSuccessLog;
use App\Models\PasswordResetCode;
use App\Service\ApiTokenService;
use App\Service\MuthobartaSmsService;
use Illuminate\Support\Carbon;

class AuthController extends Controller
{
    private function maskSensitive(array $payload): array
    {
        unset($payload['password'], $payload['password_confirmation']);
        return $payload;
    }

    private function storeLoginError(Request $request, string $message, ?User $user = null, string $level = 'error', ?\Throwable $exception = null): void
    {
        try {
            LoginError::create([
                'user_id' => $user?->id,
                'level' => $level,
                'message' => $message,
                'file' => $exception?->getFile(),
                'line' => $exception?->getLine(),
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'request_data' => json_encode($this->maskSensitive($request->all()), JSON_UNESCAPED_UNICODE),
                'stack_trace' => $exception?->getTraceAsString(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Avoid breaking authentication flow if logging fails.
        }
    }

    private function storeLoginSuccess(Request $request, User $user, ?ApiToken $token = null, string $loginType = 'login'): void
    {
        try {
            LoginSuccessLog::create([
                'user_id' => $user->id,
                'user_type' => $user->user_type,
                'role' => $user->role,
                'login_type' => $loginType,
                'token_id' => $token?->id,
                'token_name' => $token?->name,
                'token_expires_at' => $token?->expires_at,
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'request_data' => json_encode($this->maskSensitive($request->all()), JSON_UNESCAPED_UNICODE),
                'logged_in_at' => now(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Avoid breaking authentication flow if success logging fails.
        }
    }

    private function normalizePhoneForSms(?string $phone): ?string
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

    private function maskPhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);
        $length = strlen($digits);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', max(0, $length - 4)) . substr($digits, -4);
    }

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
                $digits = preg_replace('/\D+/', '', $rawPhone);

                $local = preg_replace('/^88/', '', $digits);
                $local = preg_replace('/^0/', '', $local);

                $variants = array_filter(array_unique([
                    $rawPhone,
                    $digits,
                    '+88' . '0' . $local,
                    '+88' . $local,
                    '88' . '0' . $local,
                    '88' . $local,
                    '0' . $local,
                    $local,
                ]));

                $user = User::whereIn('phone', $variants)->first();
            }

            if (!$user || !Hash::check($validated['password'], $user->password)) {
                $this->storeLoginError($request, 'Invalid credentials', $user, 'warning');
                return $this->failed('Invalid credentials', null, 401);
            }

            // Check if user is banned (not activated)
            if (isset($user->banned) && $user->banned == 1) {
                $this->storeLoginError($request, 'Login blocked for banned user', $user, 'warning');
                return $this->failed('You need to activate your account', null, 403);
            }

            $scopes = ['basic'];
            if ($user->role === 'admin') {
                $scopes[] = 'admin';
            }

            $days = $validated['expires_in_days'] ?? 30;
            $name = $validated['name'] ?? 'login-token';

            $created = ApiTokenService::create($user, $scopes, $days, $name);
            $this->storeLoginSuccess($request, $user, $created['token'], 'login');

            return $this->success('Login successful', [
                'token' => $created['plain'],
                'token_type' => 'Bearer',
                'expires_at' => $created['token']->expires_at,
                'token_id' => $created['token']->id,
                'user' => $user,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->storeLoginError($request, 'Login validation failed', null, 'warning', $e);
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            $this->storeLoginError($request, 'Login failed with server error', null, 'error', $e);
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /auth/forgot-password
     */
    public function forgotPassword(Request $request, MuthobartaSmsService $smsService)
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'email'],
            ]);

            $user = User::where('email', $validated['email'])->first();

            if (!$user) {
                return $this->failed('User not found with this email', null, 404);
            }

            if (isset($user->banned) && (int) $user->banned === 1) {
                return $this->failed('You need to activate your account', null, 403);
            }

            $phone = $this->normalizePhoneForSms($user->phone);

            if (!$phone) {
                return $this->failed('No mobile number found for this email', null, 422);
            }

            $dailySentCount = PasswordResetCode::where('phone', $phone)
                ->whereNotNull('sms_sent_at')
                ->whereDate('sms_sent_at', Carbon::today())
                ->count();

            if ($dailySentCount >= 3) {
                return $this->failed('Daily password reset OTP limit reached for this mobile number', null, 429);
            }

            $recentCodeCount = PasswordResetCode::where('user_id', $user->id)
                ->where('created_at', '>=', Carbon::now()->subMinutes(2))
                ->count();

            if ($recentCodeCount >= 1) {
                return $this->failed('Please wait before requesting another OTP', null, 429);
            }

            PasswordResetCode::where('user_id', $user->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $code = (string) random_int(100000, 999999);
            $expiresAt = Carbon::now()->addMinutes(10);

            $resetCode = PasswordResetCode::create([
                'user_id' => $user->id,
                'email' => $user->email,
                'phone' => $phone,
                'code_hash' => Hash::make($code),
                'expires_at' => $expiresAt,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $message = "Your password reset OTP is {$code}. It will expire in 10 minutes.";
            $smsResult = $smsService->send($phone, $message, 'password_reset');

            if (!$smsResult['success']) {
                $resetCode->update(['used_at' => now()]);

                return $this->failed('Could not send password reset OTP', [
                    'sms_error' => $smsResult['body'],
                ], $smsResult['status'] ?: 500);
            }

            $resetCode->update(['sms_sent_at' => now()]);

            return $this->success('Password reset OTP sent successfully', [
                'email' => $user->email,
                'phone' => $this->maskPhone($phone),
                'expires_at' => $expiresAt,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /auth/reset-password
     */
    public function resetPassword(Request $request)
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'email'],
                'otp' => ['required_without:code', 'nullable', 'digits:6'],
                'code' => ['required_without:otp', 'nullable', 'digits:6'],
                'password' => ['required', 'string', 'min:6', 'confirmed'],
            ]);

            $user = User::where('email', $validated['email'])->first();

            if (!$user) {
                return $this->failed('User not found with this email', null, 404);
            }

            if (isset($user->banned) && (int) $user->banned === 1) {
                return $this->failed('You need to activate your account', null, 403);
            }

            $code = $validated['otp'] ?? $validated['code'];
            $resetCode = PasswordResetCode::where('user_id', $user->id)
                ->where('email', $user->email)
                ->whereNull('used_at')
                ->latest()
                ->first();

            if (!$resetCode) {
                return $this->failed('Invalid or expired OTP', null, 400);
            }

            if (Carbon::now()->greaterThan($resetCode->expires_at)) {
                $resetCode->update(['used_at' => now()]);

                return $this->failed('OTP expired', null, 400);
            }

            if ($resetCode->attempts >= 5) {
                $resetCode->update(['used_at' => now()]);

                return $this->failed('Too many invalid OTP attempts', null, 429);
            }

            if (!Hash::check($code, $resetCode->code_hash)) {
                $resetCode->increment('attempts');

                return $this->failed('Invalid OTP', null, 400);
            }

            $user->password = Hash::make($validated['password']);
            $user->save();

            $resetCode->update(['used_at' => now()]);

            ApiToken::where('user_id', $user->id)->update(['is_revoked' => true]);

            return $this->success('Password reset successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
   /**
     * POST /auth/login-as-vendor
     * Checks if a vendor exists by email or phone (no password required)
     */
    public function loginAsVendor(Request $request)
    {
        try {
            $validated = $request->validate([
                'email' => ['nullable', 'email', 'required_without:phone'],
                'phone' => ['nullable', 'string', 'required_without:email'],
                'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
                'name' => ['nullable', 'string', 'max:255'],
            ]);

            $user = null;

            if (!empty($validated['email'])) {
                $user = User::where('email', $validated['email'])->where('user_type', 'vendor')->first();
            } elseif (!empty($validated['phone'])) {
                $rawPhone = trim($validated['phone']);
                $digits = preg_replace('/\D+/', '', $rawPhone);

                $local = preg_replace('/^88/', '', $digits);
                $local = preg_replace('/^0/', '', $local);

                $variants = array_filter(array_unique([
                    $rawPhone,
                    $digits,
                    '+88' . '0' . $local,
                    '+88' . $local,
                    '88' . '0' . $local,
                    '88' . $local,
                    '0' . $local,
                    $local,
                ]));

                $user = User::whereIn('phone', $variants)->where('user_type', 'vendor')->first();
            }

            if (!$user) {
                return $this->failed('Vendor not found', null, 404);
            }

            // Optionally, check if vendor is banned
            if (isset($user->banned) && $user->banned == 1) {
                return $this->failed('Vendor account is not active', null, 403);
            }

            $scopes = ['basic'];
            if ($user->role === 'admin') {
                $scopes[] = 'admin';
            }

            $days = $validated['expires_in_days'] ?? 30;
            $name = $validated['name'] ?? 'login-vendor-token';

            $created = ApiTokenService::create($user, $scopes, $days, $name);
            $this->storeLoginSuccess($request, $user, $created['token'], 'login_as_vendor');

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
