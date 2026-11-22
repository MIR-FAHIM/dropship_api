<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryAssignedInfo;
use App\Models\MarketerClient;
use App\Models\MarketerCommission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use App\Service\OrderAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Partner Portal phase 1. Worked example used below:
 * supplier ৳500 → admin price ৳600 (markup ৳100); delivery charge ৳70 − courier ৳50 (margin ৳20)
 * → platform profit ৳120 → 20% = ৳24 commission.
 */
class MarketerCommissionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $marketer;
    private User $otherMarketer;
    private User $client;
    private MarketerClient $relationship;
    private Vendor $vendor;
    private Product $product;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'Pending', 6 => 'Delivered', 7 => 'Cancelled', 8 => 'Returned', 9 => 'Completed', 10 => 'Carrybee Paid'] as $id => $name) {
            OrderStatus::updateOrCreate(['id' => $id], ['name' => $name, 'type' => 'order', 'is_active' => true]);
        }
        $this->admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->marketer = User::factory()->create(['user_type' => 'marketer', 'role' => 'marketer', 'banned' => 0, 'name' => 'Tanvir']);
        $this->otherMarketer = User::factory()->create(['user_type' => 'marketer', 'role' => 'marketer', 'banned' => 0]);
        $this->client = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0, 'name' => 'Ariful', 'phone' => '01712345891']);
        $this->relationship = MarketerClient::create([
            'marketer_user_id' => $this->marketer->id, 'dropshipper_user_id' => $this->client->id,
            'active_dropshipper_user_id' => $this->client->id, 'consent_version' => MarketerClient::CONSENT_VERSION, 'accepted_at' => now(),
        ]);
        $vendorUser = User::factory()->create(['user_type' => 'vendor', 'role' => 'vendor', 'banned' => 0]);
        $this->vendor = Vendor::create(['user_id' => $vendorUser->id, 'shop_name' => 'Shop', 'has_own_delivery_account' => false, 'is_active' => true]);
        $category = Category::create(['name' => 'Kitchen', 'slug' => 'kitchen-' . uniqid(), 'is_active' => true]);
        $this->product = Product::create([
            'name' => 'Rice Cooker', 'slug' => 'rc-' . uniqid(), 'sku' => 'RC-1', 'unit_price' => 500, 'admin_price' => 600, 'price' => 800,
            'current_stock' => 10, 'category_id' => $category->id, 'vendor_id' => $this->vendor->id, 'status' => 1,
        ]);
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($user, ['basic'])['plain']];
    }

    /**
     * A CarryBee-paid order. $tagged: placed by the marketer's client (orders of a dropshipper
     * with an active marketer are tagged automatically); otherwise by a dropshipper with no marketer.
     */
    private function order(bool $tagged = true, int $status = 10): Order
    {
        $n = ++$this->seq;
        $buyer = $tagged ? $this->client : User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $order = Order::create([
            'user_id' => $buyer->id, 'order_number' => 'ORD-M-' . $n, 'status' => $status, 'payment_status' => 'paid',
            'customer_name' => 'Customer ' . $n, 'customer_phone' => '0171111111' . $n, 'shipping_address' => 'Dhaka', 'zone' => 'Inside District',
            'subtotal' => 800, 'reseller_price' => 800, 'reseller_profit' => 200, 'shipping_fee' => 70, 'discount' => 0, 'total' => 870,
            'remaining_cod' => 870, 'vendor_id' => $this->vendor->id, 'carrybee_invoice_id' => 'IX' . $n, 'carrybee_paid_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id, 'shop_id' => $this->vendor->id, 'product_name' => 'Rice Cooker', 'sku' => 'RC-1',
            'unit_price' => 500, 'admin_price' => 600, 'reseller_price' => 800, 'qty' => 1, 'line_total' => 800, 'line_total_reseller_profit' => 200, 'status' => 1,
        ]);
        DeliveryAssignedInfo::create([
            'order_id' => $order->id, 'consignment_id' => 'FXM' . $n, 'merchant_order_id' => $order->order_number, 'delivery_company_id' => 1,
            'recipient_name' => 'C', 'recipient_phone' => '8801711111111', 'recipient_address' => 'Dhaka',
            'collectable_amount' => 870, 'delivery_fee' => 50, 'total_fee' => 50,
        ]);

        return $order->fresh();
    }

    /** Delivered + CarryBee paid: the same accounting step the webhook runs. */
    private function deliver(Order $order): void
    {
        app(OrderAccountingService::class)->processOrderDelivery($order, ['actual_courier_fee' => 50, 'actual_cod_fee' => 0]);
    }

    private function commission(Order $order, string $type = 'commission'): ?MarketerCommission
    {
        return MarketerCommission::where('order_id', $order->id)->where('type', $type)->first();
    }

    public function test_delivered_client_order_earns_rate_of_markup_plus_delivery_margin_once(): void
    {
        $order = $this->order();
        $this->deliver($order);
        $this->deliver($order); // runs once only

        $c = $this->commission($order);
        $this->assertNotNull($c);
        $this->assertSame(100.0, $c->product_markup);
        $this->assertSame(20.0, $c->delivery_margin);
        $this->assertSame(120.0, $c->base_amount);
        $this->assertSame(20.0, $c->rate);
        $this->assertSame(24.0, $c->amount);
        $this->assertSame(1, MarketerCommission::where('order_id', $order->id)->count());

        $untagged = $this->order(false);
        $this->deliver($untagged);
        $this->assertNull($this->commission($untagged), 'orders not placed through a marketer earn nothing');
    }

    public function test_custom_rate_applies_to_new_commissions_only(): void
    {
        $first = $this->order();
        $this->deliver($first);

        $this->patchJson("/api/admin/marketers/{$this->marketer->id}/commission-rate", ['rate' => 15], $this->headers($this->admin))
            ->assertOk()->assertJsonPath('data.commission_rate', 15);
        $second = $this->order();
        $this->deliver($second);

        $this->assertSame(24.0, $this->commission($first)->fresh()->amount, 'earned commission keeps its rate');
        $this->assertSame(18.0, $this->commission($second)->amount);
    }

    public function test_return_before_payout_reverses_the_commission(): void
    {
        $order = $this->order();
        $this->deliver($order);
        app(OrderAccountingService::class)->processOrderReversal($order, 'returned');

        $this->assertSame(MarketerCommission::STATUS_REVERSED, $this->commission($order)->status);
        $this->assertNull($this->commission($order, 'clawback'));
        $overview = $this->getJson('/api/marketer/overview', $this->headers($this->marketer))->assertOk()->json('data');
        $this->assertEquals(0, $overview['totals']['payable']);
    }

    public function test_payout_pays_lines_and_a_later_return_is_taken_from_the_next_payout(): void
    {
        $a = $this->order();
        $b = $this->order();
        $this->deliver($a);
        $this->deliver($b);
        $admin = $this->headers($this->admin);

        // Transaction id is needed for bKash.
        $this->postJson("/api/admin/marketers/{$this->marketer->id}/commission-payouts", ['payment_method' => 'bkash'], $admin)->assertUnprocessable();
        $invoice = $this->postJson("/api/admin/marketers/{$this->marketer->id}/commission-payouts",
            ['payment_method' => 'bkash', 'trx_id' => 'BK123', 'receiver_account' => '01711000000'], $admin)
            ->assertCreated()->assertJsonPath('data.status', 'paid')->json('data');
        $this->assertEquals(48, $invoice['total_amount_owed']);
        $this->assertSame('marketer_commission', $invoice['type']);
        $this->assertSame(MarketerCommission::STATUS_PAID, $this->commission($a)->status);

        // Nothing left to pay.
        $this->postJson("/api/admin/marketers/{$this->marketer->id}/commission-payouts", ['payment_method' => 'cash'], $admin)->assertUnprocessable();

        // Order a is returned after it was paid → −৳24 clawback, taken off the next payout.
        app(OrderAccountingService::class)->processOrderReversal($a, 'returned');
        $this->assertSame(-24.0, $this->commission($a, 'clawback')->amount);
        $c = $this->order();
        $d = $this->order();
        $this->deliver($c);
        $this->deliver($d);
        $next = $this->postJson("/api/admin/marketers/{$this->marketer->id}/commission-payouts", ['payment_method' => 'cash'], $admin)
            ->assertCreated()->json('data');
        $this->assertEquals(24, $next['total_amount_owed'], '24 + 24 − 24');

        $summary = collect($this->getJson('/api/admin/marketers/commissions', $admin)->assertOk()->json('data.marketers'))->keyBy('id');
        $this->assertEquals(72, $summary[$this->marketer->id]['paid']);
        $this->assertEquals(0, $summary[$this->marketer->id]['payable']);
    }

    public function test_unpaid_payout_can_be_paid_later_or_cancelled(): void
    {
        $order = $this->order();
        $this->deliver($order);
        $admin = $this->headers($this->admin);

        $invoice = $this->postJson("/api/admin/marketers/{$this->marketer->id}/commission-payouts", ['status' => 'pending'], $admin)
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        $this->assertSame($invoice['id'], $this->commission($order)->payout_invoice_id);

        $this->postJson("/api/admin/marketers/commission-payouts/{$invoice['id']}/cancel", ['reason' => 'wrong'], $admin)->assertOk();
        $this->assertNull($this->commission($order)->fresh()->payout_invoice_id, 'lines are payable again');

        $again = $this->postJson("/api/admin/marketers/{$this->marketer->id}/commission-payouts", ['status' => 'pending'], $admin)->json('data');
        $this->postJson("/api/admin/marketers/commission-payouts/{$again['id']}/pay", ['payment_method' => 'nagad', 'trx_id' => 'NG9'], $admin)
            ->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame(MarketerCommission::STATUS_PAID, $this->commission($order)->fresh()->status);
    }

    public function test_marketer_overview_shows_own_clients_without_private_details(): void
    {
        $this->deliver($this->order());
        $this->order(true, 1); // placed, not delivered yet → no commission

        $data = $this->getJson('/api/marketer/overview', $this->headers($this->marketer))->assertOk()->json('data');
        $this->assertEquals(20, $data['commission_rate']);
        $this->assertEquals(24, $data['totals']['payable']);
        $this->assertSame(2, $data['totals']['orders_placed']);
        $this->assertSame(1, $data['totals']['orders_delivered']);
        $this->assertSame(1, $data['totals']['active_clients']);
        $this->assertSame('Ariful', $data['clients'][0]['name']);
        $this->assertEquals(24, $data['clients'][0]['commission']);
        $this->assertArrayNotHasKey('phone', $data['clients'][0], 'phone is not part of the client consent');
        $this->assertEquals(15, $data['formula']['commission'], '20% of (25 + 50)');

        $this->assertSame([], $this->getJson('/api/marketer/overview', $this->headers($this->otherMarketer))->assertOk()->json('data.clients'));
        $this->assertSame(0, $this->getJson('/api/marketer/commissions', $this->headers($this->otherMarketer))->assertOk()->json('data.total'));
        $this->assertSame(1, $this->getJson('/api/marketer/commissions', $this->headers($this->marketer))->assertOk()->json('data.total'));
    }

    public function test_only_admins_manage_commissions_and_only_marketers_see_the_panel(): void
    {
        $this->getJson('/api/marketer/overview', $this->headers($this->client))->assertForbidden();
        $this->getJson('/api/admin/marketers/commissions', $this->headers($this->marketer))->assertForbidden();
        $this->patchJson("/api/admin/marketers/{$this->marketer->id}/commission-rate", ['rate' => 90], $this->headers($this->marketer))->assertForbidden();
        $this->postJson("/api/admin/marketers/{$this->marketer->id}/commission-payouts", ['payment_method' => 'cash'], $this->headers($this->client))->assertForbidden();
    }
}
