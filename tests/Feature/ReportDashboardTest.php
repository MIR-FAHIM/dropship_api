<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportDashboardTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create([
            'email' => 'dash_admin@test.com',
            'user_type' => 'admin',
            'role' => 'admin',
        ]);
        $this->token = ApiTokenService::create($admin, ['basic', 'admin'], 60, 'dash-token')['plain'];

        OrderStatus::create(['id' => 1, 'name' => 'Pending', 'type' => 'pending', 'is_active' => true]);
        OrderStatus::create(['id' => 2, 'name' => 'Delivered', 'type' => 'delivered', 'is_active' => true]);
        OrderStatus::create(['id' => 3, 'name' => 'Cancelled', 'type' => 'cancelled', 'is_active' => true]);
    }

    private function makeOrder(int $status, float $total, \Carbon\Carbon $createdAt): Order
    {
        $order = Order::create([
            'user_id' => User::factory()->create(['user_type' => 'dropshipper'])->id,
            'order_number' => 'ORD-' . uniqid(),
            'status' => $status,
            'payment_status' => 'unpaid',
            'subtotal' => $total,
            'reseller_price' => $total,
            'shipping_fee' => 0,
            'discount' => 0,
            'total' => $total,
        ]);
        $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $order;
    }

    public function test_dashboard_excludes_cancelled_orders_from_totals_and_breaks_down_by_status(): void
    {
        $today = \Carbon\Carbon::today();

        $this->makeOrder(1, 500.00, $today);        // pending, net
        $this->makeOrder(2, 1000.00, $today);        // delivered, net
        $this->makeOrder(3, 2000.00, $today);        // cancelled - must be excluded from totals

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/reports/dashboard');

        $response->assertStatus(200);
        $data = $response->json('data');

        // orders_count (net) must exclude the cancelled order.
        $this->assertEquals(2, $data['orders_count']);
        $this->assertEquals(3, $data['orders']['total_gross']);
        $this->assertEquals(2, $data['orders']['total_net']);
        $this->assertEquals(1, $data['orders']['cancelled_count']);

        // total_sell must exclude the cancelled order's 2000.00.
        $this->assertEquals(1500.00, $data['total_sell']);
        $this->assertEquals(1500.00, $data['today_sell']);

        $breakdown = collect($data['orders']['status_breakdown'])->keyBy('status_id');
        $this->assertEquals(1, $breakdown[1]['count']);
        $this->assertEquals(1, $breakdown[2]['count']);
        $this->assertEquals(1, $breakdown[3]['count']);
    }

    public function test_dashboard_daily_breakdown_and_growth_cover_the_requested_window(): void
    {
        $today = \Carbon\Carbon::today();

        $this->makeOrder(2, 300.00, $today);
        $this->makeOrder(2, 200.00, $today->copy()->subDays(1));
        $this->makeOrder(3, 999.00, $today->copy()->subDays(1)); // cancelled, same day
        $this->makeOrder(2, 100.00, $today->copy()->subDays(8)); // outside the default 7-day growth window

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/reports/dashboard?days=10');

        $response->assertStatus(200);
        $data = $response->json('data');

        $daily = collect($data['daily_breakdown'])->keyBy('date');
        $todayStr = $today->toDateString();
        $yesterdayStr = $today->copy()->subDays(1)->toDateString();

        $this->assertEquals(1, $daily[$todayStr]['orders_count']);
        $this->assertEquals(300.00, $daily[$todayStr]['total_sell']);

        // Yesterday had 2 orders total, 1 cancelled -> net 1, and cancelled excluded from sell.
        $this->assertEquals(2, $daily[$yesterdayStr]['orders_count_gross']);
        $this->assertEquals(1, $daily[$yesterdayStr]['cancelled_count']);
        $this->assertEquals(1, $daily[$yesterdayStr]['orders_count']);
        $this->assertEquals(200.00, $daily[$yesterdayStr]['total_sell']);

        // daily_breakdown spans exactly the requested window, oldest first.
        $this->assertCount(10, $daily);
        $this->assertEquals($today->copy()->subDays(9)->toDateString(), $data['daily_breakdown'][0]['date']);
        $this->assertEquals($todayStr, $data['daily_breakdown'][9]['date']);

        // Growth block structure is present with the two comparison windows.
        $this->assertArrayHasKey('week', $data['growth']);
        $this->assertArrayHasKey('month', $data['growth']);
        $this->assertArrayHasKey('orders_growth_percent', $data['growth']['week']);
        $this->assertArrayHasKey('sell_growth_percent', $data['growth']['week']);
    }
}
