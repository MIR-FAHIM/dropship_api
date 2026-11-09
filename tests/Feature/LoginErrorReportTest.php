<?php

namespace Tests\Feature;

use App\Models\LoginError;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginErrorReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_logs_distinct_credential_failures_but_returns_the_same_public_response(): void
    {
        $user = User::factory()->create(['banned' => 0]);
        $cases = [
            [['email' => $user->email], 'Incorrect password', $user->id],
            [['email' => 'missing@example.com'], 'No account found for this email address', null],
            [['phone' => '01700000000'], 'No account found for this phone number', null],
        ];

        foreach ($cases as [$identifier, $reason, $userId]) {
            $this->postJson('/api/auth/login', $identifier + ['password' => 'wrong-password'])
                ->assertUnauthorized()
                ->assertExactJson(['status' => 'failed', 'message' => 'Invalid credentials', 'errors' => null]);

            $log = LoginError::latest('id')->firstOrFail();
            $this->assertSame($reason, $log->message);
            $this->assertSame($userId, $log->user_id);
            $this->assertSame($identifier, json_decode($log->request_data, true));
        }
    }

    public function test_validation_details_are_available_in_the_admin_report_without_credentials(): void
    {
        $this->postJson('/api/auth/login?token=hidden-url-token', [
            'email' => 'invalid-email',
            'password_confirmation' => 'hidden-confirmation',
            'metadata' => ['token' => 'hidden-token'],
        ])->assertUnprocessable();

        $admin = User::factory()->create(['user_type' => 'admin', 'banned' => 0]);
        $response = $this->withToken(ApiTokenService::create($admin, ['basic'], 1, 'test')['plain'])
            ->getJson('/api/error-logs/login')
            ->assertOk()
            ->assertJsonPath('data.data.0.message', 'Login validation failed');

        $data = json_decode($response->json('data.data.0.request_data'), true);
        $this->assertSame(['email' => 'invalid-email'], $data['payload']);
        $this->assertArrayHasKey('email', $data['validation_errors']);
        $this->assertArrayHasKey('password', $data['validation_errors']);
        $this->assertStringNotContainsString('hidden-', $response->getContent());
    }

    public function test_non_admin_cannot_access_login_errors(): void
    {
        $vendor = User::factory()->create(['user_type' => 'vendor', 'role' => 'vendor', 'banned' => 0]);

        $this->withToken(ApiTokenService::create($vendor, ['basic'], 1, 'test')['plain'])
            ->getJson('/api/error-logs/login')
            ->assertForbidden();
    }

    public function test_inactive_account_and_internal_error_are_logged_separately(): void
    {
        $user = User::factory()->create(['banned' => 1]);
        $credentials = ['email' => $user->email, 'password' => 'password'];

        $this->postJson('/api/auth/login', $credentials)->assertForbidden();
        $this->assertDatabaseHas('login_errors', [
            'user_id' => $user->id,
            'message' => 'Account inactive or banned',
        ]);

        Hash::shouldReceive('check')->once()->andThrow(new \RuntimeException('private exception detail'));
        $this->postJson('/api/auth/login', $credentials)
            ->assertInternalServerError()
            ->assertExactJson(['status' => 'failed', 'message' => 'Something went wrong', 'errors' => null]);
        $this->assertDatabaseHas('login_errors', [
            'user_id' => $user->id,
            'level' => 'error',
            'message' => 'Login failed with server error: RuntimeException',
        ]);
    }
}
