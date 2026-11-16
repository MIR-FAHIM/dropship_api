<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDropshipperSupportTicketsTest extends TestCase
{
    use RefreshDatabase;

    private array $adminHeaders;
    private User $rahim;
    private User $karim;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->adminHeaders = ['Authorization' => 'Bearer ' . ApiTokenService::create($admin, ['basic', 'admin'])['plain']];
        $this->rahim = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->karim = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);

        $order = Order::create(['user_id' => $this->rahim->id, 'order_number' => 'ORD-777', 'status' => 1]);
        $this->ticket($this->rahim, 'Late delivery', 'open', $order->id);
        $this->ticket($this->rahim, 'Payment missing', 'in progress');
        $this->ticket($this->rahim, 'Wrong item', 'Resolved'); // mixed case still counted
        $this->ticket($this->karim, 'Other user', 'open');
    }

    private function ticket(User $user, string $title, string $status, ?int $orderId = null): SupportTicket
    {
        return SupportTicket::create([
            'user_id' => $user->id, 'order_id' => $orderId, 'support_type' => 'order',
            'title' => $title, 'description' => 'details', 'status' => $status, 'is_active' => true,
        ]);
    }

    public function test_admin_gets_only_this_dropshippers_tickets_with_summary(): void
    {
        $res = $this->getJson("/api/support-tickets/admin/user/{$this->rahim->id}", $this->adminHeaders)->assertOk();

        $titles = collect($res->json('data.data'))->pluck('title')->all();
        $this->assertSame(['Wrong item', 'Payment missing', 'Late delivery'], $titles, 'newest first, no other user');
        $this->assertSame(3, $res->json('data.total'));
        $this->assertSame(
            ['total' => 3, 'open' => 1, 'in progress' => 1, 'resolved' => 1, 'closed' => 0],
            $res->json('summary')
        );

        $withOrder = collect($res->json('data.data'))->firstWhere('title', 'Late delivery');
        $this->assertSame('ORD-777', $withOrder['order']['order_number']);
    }

    public function test_status_filter_and_pagination(): void
    {
        $open = $this->getJson("/api/support-tickets/admin/user/{$this->rahim->id}?status=open", $this->adminHeaders)->assertOk();
        $this->assertSame(['Late delivery'], collect($open->json('data.data'))->pluck('title')->all());
        $this->assertSame(3, $open->json('summary.total'), 'summary ignores the filter');

        $resolved = $this->getJson("/api/support-tickets/admin/user/{$this->rahim->id}?status=resolved", $this->adminHeaders)->assertOk();
        $this->assertSame(['Wrong item'], collect($resolved->json('data.data'))->pluck('title')->all());

        $page = $this->getJson("/api/support-tickets/admin/user/{$this->rahim->id}?per_page=2&page=2", $this->adminHeaders)->assertOk();
        $this->assertSame(['Late delivery'], collect($page->json('data.data'))->pluck('title')->all());
        $this->assertSame(2, $page->json('data.last_page'));
    }

    public function test_non_admin_cannot_use_the_admin_endpoint(): void
    {
        $headers = ['Authorization' => 'Bearer ' . ApiTokenService::create($this->karim, ['basic'])['plain']];

        $this->getJson("/api/support-tickets/admin/user/{$this->rahim->id}", $headers)->assertStatus(403);
    }
}
