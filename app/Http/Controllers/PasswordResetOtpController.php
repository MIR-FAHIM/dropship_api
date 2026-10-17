<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetConfirmationMail;
use App\Mail\ResetPasswordOtpMail;
use App\Models\ApiToken;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class PasswordResetOtpController extends Controller
{
    private function success(string $message, $data = null, int $code = 200)
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    private function failed(string $message, $errors = null, int $code = 400)
    {
        return response()->json([
            'status'  => 'failed',
            'message' => $message,
            'errors'  => $errors,
        ], $code);
    }

    /**
     * POST /api/forgot-password & /api/auth/forgot-password
     * Generates a 6-digit email OTP with rate limiting and anti-enumeration.
     */
    public function forgotPassword(Request $request)
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'string', 'email', 'max:191'],
            ]);

            $email = strtolower(trim($validated['email']));

            // 1. IP Rate Limiting (20 requests per minute)
            $ipKey = 'forgot_pwd_ip_' . $request->ip();
            if (RateLimiter::tooManyAttempts($ipKey, 20)) {
                $seconds = RateLimiter::availableIn($ipKey);
                return response()->json([
                    'status'  => 'failed',
                    'message' => "Too many requests. Please wait {$seconds} seconds.",
                ], 429);
            }
            RateLimiter::hit($ipKey, 60);

            // 2. Strict 60-second cooldown per email (Cache + DB check)
            $emailCooldownKey = 'forgot_pwd_cooldown_' . sha1($email);
            if (RateLimiter::tooManyAttempts($emailCooldownKey, 1)) {
                $seconds = RateLimiter::availableIn($emailCooldownKey);
                return response()->json([
                    'status'  => 'failed',
                    'message' => "Please wait {$seconds} seconds before requesting another code.",
                ], 429);
            }

            $recentInDb = PasswordResetOtp::where('email', $email)
                ->where('last_sent_at', '>=', now()->subSeconds(60))
                ->exists();

            if ($recentInDb) {
                return response()->json([
                    'status'  => 'failed',
                    'message' => "Please wait before requesting another code.",
                ], 429);
            }

            RateLimiter::hit($emailCooldownKey, 60);

            // 3. User Lookup (Silent handling for anti-enumeration)
            $user = User::where('email', $email)->first();

            if ($user && (!isset($user->banned) || (int) $user->banned === 0)) {
                // Invalidate prior unused OTPs for this user/email
                PasswordResetOtp::where('email', $email)
                    ->whereNull('used_at')
                    ->update(['used_at' => now()]);

                // Generate 6-digit secure numeric code
                $otp = sprintf('%06d', random_int(0, 999999));
                $expiresAt = now()->addMinutes(5);

                PasswordResetOtp::create([
                    'user_id'      => $user->id,
                    'email'        => $email,
                    'otp_hash'     => Hash::make($otp),
                    'expires_at'   => $expiresAt,
                    'attempts'     => 0,
                    'last_sent_at' => now(),
                    'ip_address'   => $request->ip(),
                    'user_agent'   => $request->userAgent(),
                ]);

                // Dispatch Email safely
                try {
                    Mail::to($user->email)->send(new ResetPasswordOtpMail($otp, 5, $user->name));
                } catch (\Throwable $mailEx) {
                    Log::error("Failed to send password reset OTP email to {$email}: " . $mailEx->getMessage());
                }
            }

            // Anti-Enumeration: Always return identical 200 response
            return response()->json([
                'status'  => 'success',
                'message' => 'If this email is registered, a password reset code has been sent.',
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            Log::error('Forgot password error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->failed('Something went wrong. Please try again later.', null, 500);
        }
    }

    /**
     * POST /api/reset-password & /api/auth/reset-password
     * Verifies 6-digit OTP and resets password inside a transaction.
     */
    public function resetPassword(Request $request)
    {
        try {
            $validated = $request->validate([
                'email'                 => ['required', 'string', 'email', 'max:191'],
                'otp'                   => ['required', 'string', 'regex:/^[0-9]{6}$/'],
                'password'              => ['required', 'string', 'min:8', 'confirmed'],
                'password_confirmation' => ['required', 'string'],
            ]);

            $email = strtolower(trim($validated['email']));

            // Rate-limiting verification attempts (10 attempts per 5 minutes per IP + email)
            $verifyRateKey = 'verify_otp_' . sha1($request->ip() . '|' . $email);
            if (RateLimiter::tooManyAttempts($verifyRateKey, 10)) {
                $seconds = RateLimiter::availableIn($verifyRateKey);
                return response()->json([
                    'status'  => 'failed',
                    'message' => "Too many verification attempts. Please wait {$seconds} seconds.",
                ], 429);
            }
            RateLimiter::hit($verifyRateKey, 300);

            return DB::transaction(function () use ($validated, $email) {
                $user = User::where('email', $email)->lockForUpdate()->first();

                if (!$user) {
                    return $this->failed('Invalid or expired verification code.', null, 400);
                }

                if (isset($user->banned) && (int) $user->banned === 1) {
                    return $this->failed('You need to activate your account', null, 403);
                }

                $otpRecord = PasswordResetOtp::where('email', $email)
                    ->whereNull('used_at')
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                if (!$otpRecord) {
                    return $this->failed('Invalid or expired verification code.', null, 400);
                }

                // Check expiry (5 minutes)
                if (now()->greaterThan($otpRecord->expires_at)) {
                    $otpRecord->update(['used_at' => now()]);
                    return $this->failed('Invalid or expired verification code.', null, 400);
                }

                // Check max attempts (5 attempts limit)
                if ($otpRecord->attempts >= 5) {
                    $otpRecord->update(['used_at' => now()]);
                    return $this->failed('Too many invalid attempts. This verification code has been locked.', null, 400);
                }

                // Verify OTP Hash
                if (!Hash::check($validated['otp'], $otpRecord->otp_hash)) {
                    $otpRecord->increment('attempts');
                    return $this->failed('Invalid or expired verification code.', null, 400);
                }

                // Mark current OTP as consumed
                $otpRecord->update(['used_at' => now()]);

                // Invalidate all remaining unused OTPs for this email
                PasswordResetOtp::where('email', $email)
                    ->whereNull('used_at')
                    ->update(['used_at' => now()]);

                // Update User Password
                $user->password = Hash::make($validated['password']);
                $user->save();

                // Revoke active API tokens / sessions
                ApiToken::where('user_id', $user->id)->update(['is_revoked' => true]);

                // Asynchronously/safely dispatch confirmation email
                try {
                    Mail::to($user->email)->send(
                        new PasswordResetConfirmationMail($user->name, now()->toDayDateTimeString())
                    );
                } catch (\Throwable $mailEx) {
                    Log::warning("Confirmation mail could not be sent to {$user->email}: " . $mailEx->getMessage());
                }

                return $this->success('Password reset successfully. Please log in with your new password.');
            });

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            Log::error('Reset password error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->failed('Something went wrong. Please try again later.', null, 500);
        }
    }
}
