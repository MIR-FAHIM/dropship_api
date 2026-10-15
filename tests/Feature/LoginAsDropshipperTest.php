<?php

namespace Tests\Feature;

use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginAsDropshipperTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): array
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
            'user_type' => 'admin',
            'banned' => 0,
        ]);

        $tokenData = ApiTokenService::create($admin, ['basic', 'admin'], 30, 'admin-token');

        return [$admin, $tokenData['plain']];
    }

    private function createRegularUser(): array
    {
        $user = User::create([
            'name' => 'Regular User',
            'email' => 'regular_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'customer',
            'user_type' => 'customer',
            'banned' => 0,
        ]);

        $tokenData = ApiTokenService::create($user, ['basic'], 30, 'user-token');

        return [$user, $tokenData['plain']];
    }

    private function createDropshipper(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Dropshipper Rahim',
            'email' => 'dropshipper_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'phone' => '01712345678',
            'role' => 'customer',
            'user_type' => 'dropshipper',
            'banned' => 0,
        ], $attributes));
    }

    public function test_unauthenticated_request_fails(): void
    {
        $response = $this->postJson('/api/auth/login-as-dropshipper', [
            'id' => 1,
        ]);

        $response->assertStatus(401);
    }

    public function test_non_admin_cannot_impersonate_dropshipper(): void
    {
        [$regularUser, $token] = $this->createRegularUser();
        $dropshipper = $this->createDropshipper();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/login-as-dropshipper', [
                'id' => $dropshipper->id,
            ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'status' => 'failed',
            'message' => 'Unauthorized. Admin access required.',
        ]);
    }

    public function test_admin_requires_at_least_one_identifier(): void
    {
        [$admin, $adminToken] = $this->createAdmin();

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/auth/login-as-dropshipper', []);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'status' => 'failed',
            'message' => 'Please provide id, user_id, email, or phone',
        ]);
    }

    public function test_admin_can_impersonate_dropshipper_by_id(): void
    {
        [$admin, $adminToken] = $this->createAdmin();
        $dropshipper = $this->createDropshipper();

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/auth/login-as-dropshipper', [
                'id' => $dropshipper->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'token',
                'token_type',
                'expires_at',
                'token_id',
                'user' => ['id', 'name', 'email', 'user_type'],
                'impersonated_by' => ['id', 'name', 'email'],
            ],
        ]);

        $this->assertEquals($dropshipper->id, $response->json('data.user.id'));
        $this->assertEquals($admin->id, $response->json('data.impersonated_by.id'));
        $this->assertEquals('Bearer', $response->json('data.token_type'));
    }

    public function test_admin_can_impersonate_dropshipper_by_user_id(): void
    {
        [$admin, $adminToken] = $this->createAdmin();
        $dropshipper = $this->createDropshipper();

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/auth/login-as-dropshipper', [
                'user_id' => $dropshipper->id,
            ]);

        $response->assertStatus(200);
        $this->assertEquals($dropshipper->id, $response->json('data.user.id'));
    }

    public function test_admin_can_impersonate_dropshipper_by_email(): void
    {
        [$admin, $adminToken] = $this->createAdmin();
        $dropshipper = $this->createDropshipper();

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/auth/login-as-dropshipper', [
                'email' => $dropshipper->email,
            ]);

        $response->assertStatus(200);
        $this->assertEquals($dropshipper->id, $response->json('data.user.id'));
    }

    public function test_admin_can_impersonate_dropshipper_by_phone(): void
    {
        [$admin, $adminToken] = $this->createAdmin();
        $dropshipper = $this->createDropshipper(['phone' => '01712345678']);

        // Test with different format '+8801712345678'
        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/auth/login-as-dropshipper', [
                'phone' => '+8801712345678',
            ]);

        $response->assertStatus(200);
        $this->assertEquals($dropshipper->id, $response->json('data.user.id'));
    }

    public function test_non_dropshipper_target_returns_404(): void
    {
        [$admin, $adminToken] = $this->createAdmin();
        [$regularUser, $token] = $this->createRegularUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/auth/login-as-dropshipper', [
                'id' => $regularUser->id,
            ]);

        $response->assertStatus(404);
        $response->assertJsonFragment([
            'status' => 'failed',
            'message' => 'Dropshipper not found',
        ]);
    }

    public function test_banned_dropshipper_blocked_unless_force(): void
    {
        [$admin, $adminToken] = $this->createAdmin();
        $bannedDropshipper = $this->createDropshipper(['banned' => 1]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/auth/login-as-dropshipper', [
                'id' => $bannedDropshipper->id,
            ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'status' => 'failed',
            'message' => 'Dropshipper account is not active',
        ]);

        // With force: true
        $forceResponse = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/auth/login-as-dropshipper', [
                'id' => $bannedDropshipper->id,
                'force' => true,
            ]);

        $forceResponse->assertStatus(200);
        $this->assertEquals($bannedDropshipper->id, $forceResponse->json('data.user.id'));
    }

    public function test_works_via_users_route_alias(): void
    {
        [$admin, $adminToken] = $this->createAdmin();
        $dropshipper = $this->createDropshipper();

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/users/login-as-dropshipper', [
                'id' => $dropshipper->id,
            ]);

        $response->assertStatus(200);
        $this->assertEquals($dropshipper->id, $response->json('data.user.id'));
    }
}
