<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryAssignedInfo;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSettlement;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use App\Service\CarrybeeWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the fix: for a real CarryBee consignment, the disbursement journey
 * (settlement creation) starts specifically at the "order.paid" webhook -
 * confirming CarryBee actually collected the COD - never merely at
 * "order.delivered". Orders with no consignment (manual/offline fulfillment)
 * have no such webhook coming, so "delivered" remains their only signal.
 */
class DisbursementTimingTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;
    private Vendor $vendor;
    private Product $product;
    private User $reseller;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'Pending', 5 => 'Assigned for Delivery', 6 => 'Delivered', 9 => 'Completed'] as $id => $name) {
            OrderStatus::updateOrCreate(['id' => $id], ['name' => $name, 'type' => 'other', 'is_active' => true]);
        }

        $admin = User::factory()->create(['email' => 'timing_admin@test.com', 'user_type' => 'admin', 'role' => 'admin']);
        $this->adminToken = ApiTokenService::create($admin, ['basic', 'admin'], 60, 'tok')['plain'];

        $this->reseller = User::factory()->create(['email' => 'timing_reseller@test.com', 'user_type' => 'dropshipper']);
        $vendorUser = User::factory()->create(['email' => 'timing_vendor@test.com', 'user_type' => 'vendor']);
        $this->vendor = Vendor::create(['user_id' => $vendorUser->id, 'shop_name' => 'Timing Shop', 'is_active' => true]);

        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'is_active' => true]);
        $this->product = Product::create([
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-T',
            'unit_price' => 500, 'admin_price' => 600, 'price' => 800,
            'current_stock' => 50, 'category_id' => $category->id, 'vendor_id' => $this->vendor->id, 'status' => 1,
        ]);
    }

    private function createOrder(int $status): Order
    {
        $order = Order::create([
            'user_id' => $this->reseller->id, 'order_number' => 'ORD-T-' . uniqid(), 'status' => $status,
            'payment_status' => 'unpaid', 'customer_name' => 'John', 'customer_phone' => '017',
            'shipping_address' => 'addr', 'zone' => 'Dhaka',
            'subtotal' => 800, 'reseller_price' => 800, 'reseller_profit' => 200,
            'shipping_fee' => 100, 'discount' => 0, 'total' => 900, 'remaining_cod' => 900,
            'vendor_id' => $this->vendor->id,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id, 'shop_id' => $this->vendor->id,
            'product_name' => $this->product->name, 'sku' => $this->product->sku,
            'unit_price' => 500, 'admin_price' => 600, 'reseller_price' => 800,
            'qty' => 1, 'line_total' => 800, 'line_total_reseller_profit' => 200, 'status' => 1,
        ]);
        return $order;
    }

    private function fireWebhook(array $payload): array
    {
        return app(CarrybeeWebhookService::class)->process($payload, json_encode($payload), 'ignored', [], true);
    }

    public function test_delivered_webhook_does_not_settle_a_consignment_order(): void
    {
        $order = $this->createOrder(5);
        DeliveryAssignedInfo::create([
            'order_id' => $order->id, 'consignment_id' => 'CONS-T-1', 'delivery_company_id' => 1,
            'recipient_name' => 'John', 'recipient_phone' => '017', 'recipient_address' => 'addr',
            'collectable_amount' => 900,
        ]);

        $response = $this->fireWebhook([
            'event' => 'order.delivered',
            'consignment_id' => 'CONS-T-1',
            'delivery_fee' => 80.00,
            'cod_fee' => 9.00,
        ]);
        $this->assertTrue($response['ok']);

        $order->refresh();
        $this->assertEquals(6, $order->status);
        // Fee data captured for later use...
        $this->assertEquals(80.00, (float) $order->actual_courier_fee);
        $this->assertEquals(9.00, (float) $order->actual_cod_fee);
        // ...but the disbursement journey must NOT have started yet.
        $this->assertFalse((bool) $order->is_carrybee_paid);
        $this->assertEquals(0, OrderSettlement::where('order_id', $order->id)->count());
    }

    public function test_paid_webhook_starts_disbursement_after_delivered(): void
    {
        $order = $this->createOrder(5);
        DeliveryAssignedInfo::create([
            'order_id' => $order->id, 'consignment_id' => 'CONS-T-2', 'delivery_company_id' => 1,
            'recipient_name' => 'John', 'recipient_phone' => '017', 'recipient_address' => 'addr',
            'collectable_amount' => 900,
        ]);

        $this->fireWebhook([
            'event' => 'order.delivered',
            'consignment_id' => 'CONS-T-2',
            'delivery_fee' => 80.00,
            'cod_fee' => 9.00,
        ]);
        $this->assertEquals(0, OrderSettlement::where('order_id', $order->id)->count());

        $response = $this->fireWebhook([
            'event' => 'order.paid',
            'consignment_id' => 'CONS-T-2',
            'invoice_id' => 'INV-T-2',
        ]);
        $this->assertTrue($response['ok']);

        $order->refresh();
        $this->assertEquals(10, $order->status);
        $this->assertTrue((bool) $order->is_carrybee_paid);
        $this->assertEquals('paid', $order->payment_status);

        // Settlements now exist, using the fee data captured at "delivered".
        $settlements = OrderSettlement::where('order_id', $order->id)->get();
        $this->assertGreaterThan(0, $settlements->count());
        $resellerSettlement = $settlements->firstWhere('settlement_type', OrderSettlement::TYPE_RESELLER_PROFIT);
        $this->assertNotNull($resellerSettlement);
        // collectable (900) - admin base price (600) - shipping fee (100) - cod fee (9) = 191
        $this->assertEquals(191.00, (float) $resellerSettlement->settleable_amount);
    }

    public function test_non_consignment_order_settles_on_delivered_via_admin_action(): void
    {
        $order = $this->createOrder(5);
        // No DeliveryAssignedInfo / consignment at all.

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson('/api/orders/update/status/' . $order->id, ['status_id' => 6]);
        $response->assertStatus(200);

        $order->refresh();
        $this->assertEquals('paid', $order->payment_status);
        $this->assertGreaterThan(0, OrderSettlement::where('order_id', $order->id)->count());
    }

    public function test_consignment_order_does_not_settle_on_manual_delivered_without_paid_confirmation(): void
    {
        $order = $this->createOrder(5);
        DeliveryAssignedInfo::create([
            'order_id' => $order->id, 'consignment_id' => 'CONS-T-3', 'delivery_company_id' => 1,
            'recipient_name' => 'John', 'recipient_phone' => '017', 'recipient_address' => 'addr',
            'collectable_amount' => 900,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson('/api/orders/update/status/' . $order->id, ['status_id' => 6]);
        $response->assertStatus(200);

        $order->refresh();
        $this->assertEquals(0, OrderSettlement::where('order_id', $order->id)->count());
    }
}
