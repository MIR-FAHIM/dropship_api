<?php

namespace Tests\Feature;

use App\Models\ProductClicks;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Activity shown on /admin-panel/dropshippers and the details header.
class DropshipperProductClicksCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_and_details_show_product_click_count_and_last_click(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $headers = ['Authorization' => 'Bearer ' . ApiTokenService::create($admin, ['basic', 'admin'])['plain']];
        $active = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $quiet = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);

        foreach (['2026-11-10 08:00:00', '2026-11-12 09:30:00', '2026-11-11 10:00:00'] as $at) {
            $c = ProductClicks::create(['product_id' => 1, 'page' => 'product_details', 'clicked_by' => $active->id, 'is_guest' => false]);
            $c->forceFill(['created_at' => $at])->save();
        }
        ProductClicks::create(['product_id' => 1, 'page' => 'reseller_product_page', 'clicked_by' => null, 'is_guest' => true]);

        $rows = collect($this->getJson('/api/users/dropshippers', $headers)->assertOk()->json('data.data'))->keyBy('id');
        $this->assertSame(3, $rows[$active->id]['product_clicks_count']);
        $this->assertStringStartsWith('2026-11-12 09:30:00', (string) $rows[$active->id]['last_product_click_at']);
        $this->assertSame(0, $rows[$quiet->id]['product_clicks_count']);
        $this->assertNull($rows[$quiet->id]['last_product_click_at']);

        $details = $this->getJson("/api/users/details/{$active->id}", $headers)->assertOk()->json('data');
        $this->assertSame(3, $details['product_clicks_count']);
        $this->assertStringStartsWith('2026-11-12 09:30:00', (string) $details['last_product_click_at']);
    }
}
