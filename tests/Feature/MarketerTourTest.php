<?php

namespace Tests\Feature;

use App\Models\MarketerClient;
use App\Models\Order;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Partner Portal F7: tour milestones = delivered orders of the marketer's own clients (progress only). */
class MarketerTourTest extends TestCase
{
    use RefreshDatabase;

    private User $marketer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marketer = User::factory()->create(['user_type' => 'marketer', 'role' => 'marketer', 'banned' => 0]);
        config(['marketer.tours' => [
            ['id' => 'T1', 'target' => 3, 'en' => 'Sajek', 'bn' => 'সাজেক'],
            ['id' => 'T2', 'target' => 6, 'en' => 'Couple', 'bn' => 'কাপল'],
        ]]);
    }

    private function h(User $u): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($u, ['basic'])['plain']];
    }

    private function client(User $marketer, string $name, bool $ended = false): MarketerClient
    {
        $d = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0, 'name' => $name]);

        return MarketerClient::create(['marketer_user_id' => $marketer->id, 'dropshipper_user_id' => $d->id,
            'consent_version' => 'v1', 'accepted_at' => now(), 'ended_at' => $ended ? now() : null]);
    }

    private function orders(MarketerClient $c, int $n, int $status): void
    {
        static $i = 0;
        for ($k = 0; $k < $n; $k++) {
            $i++;
            // marketer_client_id is not mass-assignable (Order::booted tags it) — set it directly.
            (new Order())->forceFill(['order_number' => "ORD-T{$i}", 'user_id' => $c->dropshipper_user_id, 'customer_name' => 'C', 'customer_phone' => '017',
                'shipping_address' => 'A', 'total' => 500, 'shipping_fee' => 70, 'status' => $status, 'marketer_client_id' => $c->id])->save();
        }
    }

    public function test_progress_counts_only_delivered_orders_of_own_clients(): void
    {
        $a = $this->client($this->marketer, 'Shop A');
        $b = $this->client($this->marketer, 'Shop B', ended: true);
        $this->orders($a, 2, 10);   // CarryBee paid
        $this->orders($a, 1, 9);    // completed
        $this->orders($b, 1, 6);    // delivered (client since ended — still counts)
        $this->orders($a, 3, 7);    // cancelled: not counted
        $this->orders($a, 2, 2);    // not delivered yet: not counted
        $other = User::factory()->create(['user_type' => 'marketer', 'role' => 'marketer', 'banned' => 0]);
        $this->orders($this->client($other, 'Not mine'), 5, 10);

        $d = $this->getJson('/api/marketer/tours', $this->h($this->marketer))->assertOk()->json('data');

        $this->assertSame(4, $d['delivered_orders']);
        $this->assertTrue($d['milestones'][0]['reached']);
        $this->assertSame('T2', $d['current']['id']);
        $this->assertSame(2, $d['current']['remaining']);
        $this->assertSame(66, $d['current']['percent']);
        $this->assertSame(1, $d['reached_count']);
        $this->assertFalse($d['all_reached']);
        $this->assertSame([['Shop A', 3], ['Shop B', 1]], array_map(fn ($c) => [$c['name'], $c['delivered']], $d['clients']));
        $this->assertNotEmpty($d['global_tours']);
    }

    public function test_all_reached_and_empty_marketer(): void
    {
        $d = $this->getJson('/api/marketer/tours', $this->h($this->marketer))->assertOk()->json('data');
        $this->assertSame(0, $d['delivered_orders']);
        $this->assertSame('T1', $d['current']['id']);

        $this->orders($this->client($this->marketer, 'Big'), 7, 10);
        $d = $this->getJson('/api/marketer/tours', $this->h($this->marketer))->json('data');
        $this->assertTrue($d['all_reached']);
        $this->assertNull($d['current']);
    }

    public function test_only_marketers(): void
    {
        $drop = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->assertContains($this->getJson('/api/marketer/tours', $this->h($drop))->status(), [401, 403]);
    }
}
