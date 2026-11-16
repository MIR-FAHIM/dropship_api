<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAllOrdersCancelledFilterTest extends TestCase
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

        $this->order($this->rahim, 'R-PENDING', 1, '2026-11-05 06:00:00');
        $this->order($this->rahim, 'R-DELIVERED', 6, '2026-11-06 06:00:00');
        $this->order($this->rahim, 'R-CANCEL-5', 7, '2026-11-05 06:00:00');
        $this->order($this->rahim, 'R-CANCEL-7', 7, '2026-11-07 06:00:00');
        $this->order($this->karim, 'K-CANCEL', 7, '2026-11-05 06:00:00');
        $this->order($this->karim, 'K-RETURNED', 8, '2026-11-05 06:00:00');
    }

    private function order(User $user, string $number, int $status, string $createdAtUtc): Order
    {
        $order = Order::create(['user_id' => $user->id, 'order_number' => $number, 'status' => $status]);
        $order->forceFill(['created_at' => $createdAtUtc])->save();

        return $order;
    }

    private function fetch(array $query): array
    {
        return $this->getJson('/api/orders/all/orders?' . http_build_query($query), $this->headers)
            ->assertOk()->json('data');
    }

    private function numbers(array $query): array
    {
        return collect($this->fetch($query)['data'])->pluck('order_number')->sort()->values()->all();
    }

    public function test_default_list_excludes_cancelled_orders_and_totals_match(): void
    {
        $page = $this->fetch([]);

        $this->assertSame(['K-RETURNED', 'R-DELIVERED', 'R-PENDING'], collect($page['data'])->pluck('order_number')->sort()->values()->all());
        $this->assertSame(3, $page['total']);
    }

    public function test_cancelled_only_returns_only_cancelled_orders(): void
    {
        $page = $this->fetch(['cancelled' => 'only']);

        $this->assertSame(['K-CANCEL', 'R-CANCEL-5', 'R-CANCEL-7'], collect($page['data'])->pluck('order_number')->sort()->values()->all());
        $this->assertSame(3, $page['total']);
        $ids = collect($page['data'])->pluck('id');
        $this->assertSame([7], Order::whereIn('id', $ids)->pluck('status')->map(fn ($s) => (int) $s)->unique()->values()->all());
    }

    public function test_cancelled_include_returns_everything(): void
    {
        $this->assertCount(6, $this->numbers(['cancelled' => 'include']));
    }

    public function test_other_filters_still_apply_with_cancelled_only(): void
    {
        $this->assertSame(['R-CANCEL-5', 'R-CANCEL-7'], $this->numbers(['cancelled' => 'only', 'dropshipper_id' => $this->rahim->id]));
        $this->assertSame(['K-CANCEL', 'R-CANCEL-5'], $this->numbers(['cancelled' => 'only', 'date_from' => '2026-11-05', 'date_to' => '2026-11-05']));
        $this->assertSame(['K-CANCEL'], $this->numbers(['cancelled' => 'only', 'search' => 'K-CANCEL']));
    }

    public function test_other_filters_still_apply_on_default_list(): void
    {
        $this->assertSame(['R-DELIVERED', 'R-PENDING'], $this->numbers(['dropshipper_id' => $this->rahim->id]));
        $this->assertSame(['K-RETURNED', 'R-PENDING'], $this->numbers(['date_from' => '2026-11-05', 'date_to' => '2026-11-05']));
        $this->assertSame([], $this->numbers(['search' => 'CANCEL']));
    }

    public function test_explicit_status_filter_wins_over_default_exclusion(): void
    {
        $this->assertSame(['K-CANCEL', 'R-CANCEL-5', 'R-CANCEL-7'], $this->numbers(['status' => 7]));
        $this->assertSame(['R-DELIVERED'], $this->numbers(['status' => 6]));
    }
}
