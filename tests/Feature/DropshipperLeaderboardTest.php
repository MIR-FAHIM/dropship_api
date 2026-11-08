<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DropshipperLeaderboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_leaderboard_ranks_dropshippers_with_orders_and_sums_recorded_profit(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $first = User::factory()->create(['user_type' => 'dropshipper']);
        $second = User::factory()->create(['user_type' => 'dropshipper']);
        User::factory()->create(['user_type' => 'dropshipper']);
        $customer = User::factory()->create(['user_type' => 'customer']);

        Order::create(['user_id' => $first->id, 'status' => 1, 'reseller_profit' => 100.25]);
        Order::create(['user_id' => $first->id, 'status' => 9, 'reseller_profit' => 49.50]);
        Order::create(['user_id' => $second->id, 'status' => 9, 'reseller_profit' => 200]);
        Order::create(['user_id' => $customer->id, 'status' => 1, 'reseller_profit' => 20]);

        $token = bin2hex(random_bytes(20));
        ApiToken::create([
            'user_id' => $admin->id,
            'token_hash' => hash('sha256', $token),
            'is_revoked' => false,
            'expires_at' => now()->addDay(),
        ]);
        $headers = ['Authorization' => 'Bearer '.$token];
        $endpoint = '/api/users/dropshippers/leaderboard?per_page=1';

        $this->getJson($endpoint, $headers)
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.data.0.id', $first->id)
            ->assertJsonPath('data.data.0.rank', 1)
            ->assertJsonPath('data.data.0.total_order_count', 2)
            ->assertJsonPath('data.data.0.total_profit', 149.75);

        $this->getJson($endpoint.'&page=2', $headers)
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $second->id)
            ->assertJsonPath('data.data.0.rank', 2);
    }
}
