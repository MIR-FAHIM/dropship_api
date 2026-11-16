<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Order;
use App\Models\ResellerTransaction;
use App\Models\User;
use App\Service\ApiTokenService;
use App\Service\FirebasePushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Admin in-app + push alerts for: new support ticket, advance paid, dropshipper registered.
class AdminAlertNotificationsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{user_id:int, title:string, body:string, data:array}> */
    private array $pushes = [];
    private User $admin;
    private User $adminNoDevice;
    private User $dropshipper;

    protected function setUp(): void
    {
        parent::setUp();

        $pushes = &$this->pushes;
        $this->app->instance(FirebasePushNotificationService::class, new class($pushes) extends FirebasePushNotificationService {
            public function __construct(private array &$log) {}
            public function sendToUser(User $user, string $title, string $body, array $data = []): array
            {
                $this->log[] = ['user_id' => $user->id, 'title' => $title, 'body' => $body, 'data' => $data];
                return ['success' => true];
            }
        });

        $this->admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0, 'device_token' => 'admin-device']);
        $this->adminNoDevice = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0, 'device_token' => null]);
        $this->dropshipper = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0, 'name' => 'Rahim', 'phone' => '01711111111']);
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($user, ['basic'])['plain']];
    }

    private function adminInApp(string $title)
    {
        return Notification::where('title', $title)->whereIn('send_to', [$this->admin->id, $this->adminNoDevice->id])->get();
    }

    private function order(): Order
    {
        return Order::create([
            'user_id' => $this->dropshipper->id, 'order_number' => 'ORD-A1', 'status' => 1, 'payment_status' => 'unpaid',
            'customer_name' => 'C', 'customer_phone' => '017', 'shipping_address' => 'D', 'subtotal' => 800,
            'shipping_fee' => 70, 'discount' => 0, 'total' => 870, 'remaining_cod' => 870,
        ]);
    }

    public function test_support_ticket_alerts_admins(): void
    {
        $this->postJson('/api/support-tickets/add', [
            'user_id' => $this->dropshipper->id, 'support_type' => 'order',
            'title' => 'Parcel not delivered', 'description' => 'Customer is waiting.',
        ], $this->headers($this->dropshipper))->assertCreated();

        $this->assertCount(2, $this->adminInApp('New support ticket'), 'every admin gets the in-app alert');
        $this->assertCount(1, $this->pushes, 'push only to admins with a device token');
        $this->assertSame($this->admin->id, $this->pushes[0]['user_id']);
        $this->assertSame('New support ticket', $this->pushes[0]['title']);
        $this->assertSame('Rahim: Parcel not delivered', $this->pushes[0]['body']);
        $this->assertSame('support_ticket', $this->pushes[0]['data']['type']);
    }

    public function test_mfs_advance_alerts_admins_to_verify(): void
    {
        $order = $this->order();

        $this->postJson("/api/orders/{$order->id}/pay-advance", [
            'amount' => 130, 'payment_method' => 'bkash', 'trx_id' => 'TRX123',
        ], $this->headers($this->dropshipper))->assertOk();

        $this->assertCount(2, $this->adminInApp('Advance to verify'));
        $this->assertCount(1, $this->pushes);
        $this->assertSame('Advance to verify', $this->pushes[0]['title']);
        $this->assertSame('Rahim paid ৳130 via BKASH for ORD-A1. Trx TRX123. Verify it.', $this->pushes[0]['body']);
        $this->assertSame((string) $order->id, $this->pushes[0]['data']['order_id']);
    }

    public function test_wallet_advance_alerts_admins(): void
    {
        ResellerTransaction::create(['reseller_id' => $this->dropshipper->id, 'amount' => 500, 'trx_id' => 'SEED-1',
            'trx_type' => 'credit', 'type' => 'manual_seed', 'status' => 'completed']);
        $order = $this->order();

        $this->postJson("/api/orders/{$order->id}/pay-advance", [
            'amount' => 130, 'pay_from_wallet' => true,
        ], $this->headers($this->dropshipper))->assertOk();

        $this->assertCount(2, $this->adminInApp('Advance paid'));
        $this->assertSame('Rahim paid ৳130 via wallet for ORD-A1.', $this->pushes[0]['body']);
        $this->assertSame('wallet', $this->pushes[0]['data']['method']);
    }

    public function test_failed_advance_sends_no_alert(): void
    {
        $order = $this->order(); // no wallet balance

        $this->postJson("/api/orders/{$order->id}/pay-advance", [
            'amount' => 130, 'pay_from_wallet' => true,
        ], $this->headers($this->dropshipper))->assertStatus(422);

        $this->assertSame([], $this->pushes);
        $this->assertCount(0, $this->adminInApp('Advance paid'));
    }

    public function test_dropshipper_registration_alerts_admins(): void
    {
        $this->postJson('/api/users/dropshipper-register', [
            'name' => 'Karim Seller', 'email' => 'karim.seller@example.com', 'phone' => '01822222222', 'address' => 'Dhaka',
        ])->assertCreated();

        $this->assertCount(2, $this->adminInApp('New dropshipper'));
        $this->assertCount(1, $this->pushes);
        $this->assertSame('Karim Seller (01822222222) registered. Waiting for activation.', $this->pushes[0]['body']);
        $this->assertSame('dropshipper_registered', $this->pushes[0]['data']['type']);
    }

    public function test_push_failure_does_not_break_the_action(): void
    {
        $this->app->instance(FirebasePushNotificationService::class, new class extends FirebasePushNotificationService {
            public function __construct() {}
            public function sendToUser(User $user, string $title, string $body, array $data = []): array
            {
                throw new \RuntimeException('FCM down');
            }
        });

        $this->postJson('/api/users/dropshipper-register', [
            'name' => 'Nadia', 'email' => 'nadia@example.com', 'phone' => '01933333333', 'address' => 'Dhaka',
        ])->assertCreated();

        $this->assertCount(2, $this->adminInApp('New dropshipper'), 'in-app alert still saved');
    }
}
