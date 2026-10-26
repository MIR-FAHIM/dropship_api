<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckAdminTest extends TestCase
{
    use RefreshDatabase;

    private function createTokenForUser(User $user): string
    {
        $plain = 'token-' . uniqid();
        ApiToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(7),
            'is_revoked' => false,
        ]);
        return $plain;
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/auth/check-admin');
        $response->assertStatus(401);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer invalid-token')
            ->getJson('/api/auth/check-admin');
        $response->assertStatus(401);
    }

    public function test_banned_admin_is_rejected(): void
    {
        $bannedAdmin = User::factory()->create([
            'user_type' => 'admin',
            'role' => 'admin',
            'banned' => 1,
        ]);

        $token = $this->createTokenForUser($bannedAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/check-admin');

        $response->assertStatus(403);
        $response->assertJson([
            'status' => 'failed',
            'message' => 'Your account is deactivated or banned',
        ]);
    }

    public function test_non_admin_user_is_forbidden(): void
    {
        $customer = User::factory()->create([
            'user_type' => 'customer',
            'role' => 'dropshipper',
            'banned' => 0,
        ]);

        $token = $this->createTokenForUser($customer);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/check-admin');

        $response->assertStatus(403);
        $response->assertJson([
            'status' => 'failed',
            'message' => 'Unauthorized: Admin access required',
        ]);
    }

    public function test_active_admin_user_type_is_allowed(): void
    {
        $admin = User::factory()->create([
            'user_type' => 'admin',
            'role' => null,
            'banned' => 0,
        ]);

        $token = $this->createTokenForUser($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/check-admin');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Admin access verified',
            'data' => [
                'is_admin' => true,
            ],
        ]);
    }

    public function test_active_admin_role_is_allowed(): void
    {
        $admin = User::factory()->create([
            'user_type' => 'customer',
            'role' => 'admin',
            'banned' => 0,
        ]);

        $token = $this->createTokenForUser($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/check-admin');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Admin access verified',
            'data' => [
                'is_admin' => true,
            ],
        ]);
    }

    public function test_product_manager_is_allowed(): void
    {
        $pm = User::factory()->create([
            'user_type' => 'staff',
            'role' => 'product manager',
            'banned' => 0,
        ]);

        $token = $this->createTokenForUser($pm);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/check-admin');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Admin access verified',
            'data' => [
                'is_admin' => true,
            ],
        ]);
    }
}
