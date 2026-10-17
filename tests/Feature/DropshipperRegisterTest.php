<?php

namespace Tests\Feature;

use App\Models\RegistrationErrorLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DropshipperRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_without_password_succeeds_and_autogenerates_password(): void
    {
        $payload = [
            'name'    => 'Yunus Mohammad',
            'email'   => 'yunusmohammad.bd@gmail.com',
            'phone'   => '+8801576953968',
            'address' => 'Dhaka',
        ];

        $response = $this->postJson('/api/users/dropshipper-register', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Dropshipper registered successfully',
                'data'    => [
                    'token_type' => 'Bearer',
                    'user'       => [
                        'name'      => 'Yunus Mohammad',
                        'email'     => 'yunusmohammad.bd@gmail.com',
                        'phone'     => '+8801576953968',
                        'user_type' => 'dropshipper',
                        'banned'    => 1,
                    ],
                ],
            ]);

        $user = User::where('email', 'yunusmohammad.bd@gmail.com')->first();
        $this->assertNotNull($user);
        // Password was generated from phone digits and hashed
        $this->assertTrue(Hash::check('8801576953968', $user->password));
    }

    public function test_register_with_short_pin_password_succeeds(): void
    {
        $payload = [
            'name'     => 'Karim Mia',
            'email'    => 'karim@example.com',
            'password' => '1234',
            'phone'    => '01711111111',
        ];

        $response = $this->postJson('/api/users/dropshipper-register', $payload);

        $response->assertStatus(201);
        $user = User::where('email', 'karim@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('1234', $user->password));
    }

    public function test_register_with_whitespace_in_email_and_phone_is_sanitized(): void
    {
        $payload = [
            'name'    => ' Rahim Uddin ',
            'email'   => '  RAHIM@EXAMPLE.COM ',
            'phone'   => ' 01822222222 ',
        ];

        $response = $this->postJson('/api/users/dropshipper-register', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'rahim@example.com',
            'phone' => '01822222222',
            'name'  => 'Rahim Uddin',
        ]);
    }

    public function test_register_with_existing_pending_email_returns_409_friendly_message(): void
    {
        User::create([
            'name'      => 'Existing Dropshipper',
            'email'     => 'pending@example.com',
            'password'  => bcrypt('password123'),
            'user_type' => 'dropshipper',
            'banned'    => 1,
        ]);

        $response = $this->postJson('/api/users/dropshipper-register', [
            'name'  => 'Pending Again',
            'email' => 'pending@example.com',
            'phone' => '01933333333',
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'status'  => 'failed',
                'message' => 'An account with this email has already registered and is currently awaiting admin activation.',
            ]);
    }

    public function test_validation_error_is_logged_with_exact_field_errors(): void
    {
        // Missing required 'name' and invalid email
        $response = $this->postJson('/api/users/dropshipper-register', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422);

        $log = RegistrationErrorLog::latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Errors:', $log->message);
        $this->assertStringContainsString('name', $log->message);
        $this->assertStringContainsString('email', $log->message);
    }

    public function test_phone_and_email_are_both_strictly_required(): void
    {
        // 1. Missing phone
        $responseWithoutPhone = $this->postJson('/api/dropshipper-register', [
            'name'  => 'Test User',
            'email' => 'testuser@example.com',
        ]);
        $responseWithoutPhone->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        // 2. Missing email
        $responseWithoutEmail = $this->postJson('/api/dropshipper-register', [
            'name'  => 'Test User',
            'phone' => '01700000000',
        ]);
        $responseWithoutEmail->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_root_dropshipper_register_route_succeeds_without_token(): void
    {
        $payload = [
            'name'  => 'Root Route User',
            'email' => 'rootuser@example.com',
            'phone' => '01799999999',
        ];

        $response = $this->postJson('/api/dropshipper-register', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Dropshipper registered successfully',
            ]);
    }
}
