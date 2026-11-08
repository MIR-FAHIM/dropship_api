<?php

namespace Tests\Feature;

use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderCompletedCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtered_order_list_includes_each_dropshippers_historical_completed_count(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $first = User::factory()->create(['user_type' => 'dropshipper']);
        $second = User::factory()->create(['user_type' => 'dropshipper']);

        foreach ([$first, $second] as $dropshipper) {
            $dropshipper->orders()->create(['status' => 1]);
            $dropshipper->orders()->create(['status' => 7]);
            $dropshipper->orders()->create(['status' => 10]);
            $dropshipper->orders()->createMany(array_fill(0, 4, ['status' => 9]));
        }
        $second->orders()->create(['status' => 9]);

        $token = ApiTokenService::create($admin, ['basic', 'admin']);
        $response = $this->getJson('/api/orders/all/orders?status=1', [
            'Authorization' => 'Bearer '.$token['plain'],
        ])->assertOk()->assertJsonCount(2, 'data.data');

        $counts = collect($response->json('data.data'))->pluck('user.completed_order_count', 'user.id');
        $this->assertSame(5, $counts[$first->id]);
        $this->assertSame(6, $counts[$second->id]);
    }
}
