<?php

namespace Tests\Feature;

use App\Mail\PasswordResetConfirmationMail;
use App\Mail\ResetPasswordOtpMail;
use App\Models\ApiToken;
use App\Models\PasswordResetOtp;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PasswordResetOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('forgot_pwd_ip_127.0.0.1');
    }

    private function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name'      => 'Test User',
            'email'     => 'test_' . uniqid() . '@example.com',
            'password'  => Hash::make('OldPassword123!'),
            'role'      => 'dropshipper',
            'user_type' => 'dropshipper',
            'banned'    => 0,
        ], $overrides));
    }

    public function test_forgot_password_sends_email_and_hashes_otp_for_registered_user(): void
    {
        Mail::fake();
        $user = $this->createUser(['email' => 'validuser@example.com']);

        $response = $this->postJson('/api/forgot-password', [
            'email' => 'validuser@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'success',
                'message' => 'If this email is registered, a password reset code has been sent.',
            ]);

        // Verify OTP is hashed in DB
        $otpRecord = PasswordResetOtp::where('email', 'validuser@example.com')->first();
        $this->assertNotNull($otpRecord);
        $this->assertNotEquals('123456', $otpRecord->otp_hash);
        $this->assertTrue($otpRecord->expires_at->isFuture());

        // Verify Mail sent
        Mail::assertSent(ResetPasswordOtpMail::class, function ($mail) use ($user) {
            return $mail->hasTo('validuser@example.com');
        });
    }

    public function test_forgot_password_anti_enumeration_returns_same_response_for_unregistered_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'success',
                'message' => 'If this email is registered, a password reset code has been sent.',
            ]);

        // No mail dispatched
        Mail::assertNothingSent();

        // No DB record created
        $this->assertDatabaseMissing('password_reset_otps', [
            'email' => 'nonexistent@example.com',
        ]);
    }

    public function test_forgot_password_60_second_cooldown_blocks_rapid_requests(): void
    {
        Mail::fake();
        $user = $this->createUser(['email' => 'cooldown@example.com']);

        // First request: succeeds
        $res1 = $this->postJson('/api/forgot-password', ['email' => 'cooldown@example.com']);
        $res1->assertStatus(200);

        // Immediate second request: blocked with 429
        $res2 = $this->postJson('/api/forgot-password', ['email' => 'cooldown@example.com']);
        $res2->assertStatus(429);
    }

    public function test_forgot_password_invalidates_previous_unused_otps(): void
    {
        Mail::fake();
        $user = $this->createUser(['email' => 'multi_otp@example.com']);

        // Create initial OTP
        $firstOtp = PasswordResetOtp::create([
            'user_id'      => $user->id,
            'email'        => 'multi_otp@example.com',
            'otp_hash'     => Hash::make('111111'),
            'expires_at'   => now()->addMinutes(5),
            'last_sent_at' => now()->subMinutes(2), // Past cooldown
        ]);

        RateLimiter::clear('forgot_pwd_cooldown_' . sha1('multi_otp@example.com'));

        $this->postJson('/api/forgot-password', ['email' => 'multi_otp@example.com']);

        // First OTP should now be marked as used
        $this->assertNotNull($firstOtp->fresh()->used_at);

        // A new unused OTP exists
        $newOtp = PasswordResetOtp::where('email', 'multi_otp@example.com')->whereNull('used_at')->first();
        $this->assertNotNull($newOtp);
        $this->assertNotEquals($firstOtp->id, $newOtp->id);
    }

    public function test_reset_password_succeeds_with_valid_otp_and_revokes_tokens(): void
    {
        Mail::fake();
        $user = $this->createUser(['email' => 'reset_success@example.com']);

        // Create active token for user
        $tokenData = ApiTokenService::create($user, ['basic'], 30, 'user-token');

        // Create OTP
        $otp = '582910';
        PasswordResetOtp::create([
            'user_id'      => $user->id,
            'email'        => 'reset_success@example.com',
            'otp_hash'     => Hash::make($otp),
            'expires_at'   => now()->addMinutes(5),
            'last_sent_at' => now(),
        ]);

        $response = $this->postJson('/api/reset-password', [
            'email'                 => 'reset_success@example.com',
            'otp'                   => $otp,
            'password'              => 'NewSecurePass2026!',
            'password_confirmation' => 'NewSecurePass2026!',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Password reset successfully. Please log in with your new password.',
            ]);

        // Verify password updated
        $this->assertTrue(Hash::check('NewSecurePass2026!', $user->fresh()->password));

        // Verify token was revoked
        $this->assertEquals(1, $tokenData['token']->fresh()->is_revoked);

        // Verify confirmation email sent
        Mail::assertSent(PasswordResetConfirmationMail::class, function ($mail) {
            return $mail->hasTo('reset_success@example.com');
        });
    }

    public function test_reset_password_fails_with_invalid_otp_and_increments_attempts(): void
    {
        $user = $this->createUser(['email' => 'wrong_otp@example.com']);

        $otpRecord = PasswordResetOtp::create([
            'user_id'      => $user->id,
            'email'        => 'wrong_otp@example.com',
            'otp_hash'     => Hash::make('654321'),
            'expires_at'   => now()->addMinutes(5),
            'attempts'     => 0,
            'last_sent_at' => now(),
        ]);

        $response = $this->postJson('/api/reset-password', [
            'email'                 => 'wrong_otp@example.com',
            'otp'                   => '999999',
            'password'              => 'NewSecurePass2026!',
            'password_confirmation' => 'NewSecurePass2026!',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'status'  => 'failed',
                'message' => 'Invalid or expired verification code.',
            ]);

        // Attempts incremented to 1
        $this->assertEquals(1, $otpRecord->fresh()->attempts);
    }

    public function test_reset_password_locks_otp_after_5_failed_attempts(): void
    {
        $user = $this->createUser(['email' => 'locked_otp@example.com']);

        $otpRecord = PasswordResetOtp::create([
            'user_id'      => $user->id,
            'email'        => 'locked_otp@example.com',
            'otp_hash'     => Hash::make('654321'),
            'expires_at'   => now()->addMinutes(5),
            'attempts'     => 5, // Already reached limit
            'last_sent_at' => now(),
        ]);

        $response = $this->postJson('/api/reset-password', [
            'email'                 => 'locked_otp@example.com',
            'otp'                   => '654321', // Even if correct OTP
            'password'              => 'NewSecurePass2026!',
            'password_confirmation' => 'NewSecurePass2026!',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'status'  => 'failed',
                'message' => 'Too many invalid attempts. This verification code has been locked.',
            ]);
    }

    public function test_reset_password_fails_for_expired_otp(): void
    {
        $user = $this->createUser(['email' => 'expired_otp@example.com']);

        PasswordResetOtp::create([
            'user_id'      => $user->id,
            'email'        => 'expired_otp@example.com',
            'otp_hash'     => Hash::make('654321'),
            'expires_at'   => now()->subMinutes(1), // Expired
            'last_sent_at' => now()->subMinutes(6),
        ]);

        $response = $this->postJson('/api/reset-password', [
            'email'                 => 'expired_otp@example.com',
            'otp'                   => '654321',
            'password'              => 'NewSecurePass2026!',
            'password_confirmation' => 'NewSecurePass2026!',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'status'  => 'failed',
                'message' => 'Invalid or expired verification code.',
            ]);
    }

    public function test_reset_password_fails_for_already_used_otp(): void
    {
        $user = $this->createUser(['email' => 'used_otp@example.com']);

        PasswordResetOtp::create([
            'user_id'      => $user->id,
            'email'        => 'used_otp@example.com',
            'otp_hash'     => Hash::make('654321'),
            'expires_at'   => now()->addMinutes(5),
            'used_at'      => now(), // Already used
            'last_sent_at' => now(),
        ]);

        $response = $this->postJson('/api/reset-password', [
            'email'                 => 'used_otp@example.com',
            'otp'                   => '654321',
            'password'              => 'NewSecurePass2026!',
            'password_confirmation' => 'NewSecurePass2026!',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'status'  => 'failed',
                'message' => 'Invalid or expired verification code.',
            ]);
    }

    public function test_auth_prefixed_routes_work_identically(): void
    {
        Mail::fake();
        $user = $this->createUser(['email' => 'authprefix@example.com']);

        // Check /api/auth/forgot-password route
        $res1 = $this->postJson('/api/auth/forgot-password', ['email' => 'authprefix@example.com']);
        $res1->assertStatus(200);

        $otpRecord = PasswordResetOtp::where('email', 'authprefix@example.com')->first();
        $this->assertNotNull($otpRecord);

        // Reset with correct OTP via /api/auth/reset-password
        $otp = '123456';
        $otpRecord->update(['otp_hash' => Hash::make($otp)]);

        $res2 = $this->postJson('/api/auth/reset-password', [
            'email'                 => 'authprefix@example.com',
            'otp'                   => $otp,
            'password'              => 'AnotherPass1234!',
            'password_confirmation' => 'AnotherPass1234!',
        ]);
        $res2->assertStatus(200);
    }
}
