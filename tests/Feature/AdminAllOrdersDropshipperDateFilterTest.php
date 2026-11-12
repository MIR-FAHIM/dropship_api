<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAllOrdersDropshipperDateFilterTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;
    private User $rahim;
    private User $karim;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->headers = ['Authorization' => 'Bearer ' . ApiTokenService::create($admin, ['basic', 'admin'])['plain']];
        $this->rahim = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->karim = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
    }

    private function order(User $user, string $number, string $createdAtUtc): Order
    {
        $order = Order::create(['user_id' => $user->id, 'order_number' => $number, 'status' => 1]);
        $order->forceFill(['created_at' => $createdAtUtc])->save();

        return $order;
    }

    private function numbers(array $query): array
    {
        return collect($this->getJson('/api/orders/all/orders?' . http_build_query($query), $this->headers)
            ->assertOk()->json('data.data'))->pluck('order_number')->sort()->values()->all();
    }

    public function test_filters_by_dropshipper(): void
    {
        $this->order($this->rahim, 'R-1', '2026-11-05 06:00:00');
        $this->order($this->rahim, 'R-2', '2026-11-06 06:00:00');
        $this->order($this->karim, 'K-1', '2026-11-05 06:00:00');

        $this->assertSame(['R-1', 'R-2'], $this->numbers(['dropshipper_id' => $this->rahim->id]));
        $this->assertSame(['K-1'], $this->numbers(['dropshipper_id' => $this->karim->id]));
        $this->assertCount(3, $this->numbers(['dropshipper_id' => 'all']));
    }

    public function test_filters_by_date_range_in_bangladesh_time(): void
    {
        // 4 Nov 20:00 UTC = 5 Nov 02:00 Dhaka -> belongs to 5 Nov.
        $this->order($this->rahim, 'EARLY-5', '2026-11-04 20:00:00');
        // 5 Nov 17:30 UTC = 5 Nov 23:30 Dhaka -> still 5 Nov.
        $this->order($this->rahim, 'LATE-5', '2026-11-05 17:30:00');
        // 5 Nov 18:30 UTC = 6 Nov 00:30 Dhaka -> 6 Nov.
        $this->order($this->rahim, 'EARLY-6', '2026-11-05 18:30:00');
        // 3 Nov Dhaka.
        $this->order($this->rahim, 'DAY-3', '2026-11-03 06:00:00');

        $this->assertSame(['EARLY-5', 'LATE-5'], $this->numbers(['date_from' => '2026-11-05', 'date_to' => '2026-11-05']));
        $this->assertSame(['EARLY-5', 'EARLY-6', 'LATE-5'], $this->numbers(['date_from' => '2026-11-05']));
        $this->assertSame(['DAY-3', 'EARLY-5', 'LATE-5'], $this->numbers(['date_to' => '2026-11-05']));
        // Bad dates are ignored rather than erroring.
        $this->assertCount(4, $this->numbers(['date_from' => 'yesterday']));
    }

    public function test_dropshipper_and_date_combine(): void
    {
        $this->order($this->rahim, 'R-5', '2026-11-05 06:00:00');
        $this->order($this->rahim, 'R-7', '2026-11-07 06:00:00');
        $this->order($this->karim, 'K-5', '2026-11-05 06:00:00');

        $this->assertSame(['R-5'], $this->numbers(['dropshipper_id' => $this->rahim->id, 'date_from' => '2026-11-05', 'date_to' => '2026-11-06']));
    }
}
