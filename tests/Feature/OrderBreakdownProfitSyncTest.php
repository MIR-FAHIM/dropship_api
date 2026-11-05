<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reproduces the admin-panel vs dropshipper-panel reseller_profit mismatch:
 * adjusting an order's price via the Financial Stake Breakdown modal must
 * persist to orders.reseller_profit, and that value must survive a later
 * plain order update (e.g. editing shipping_fee) instead of being silently
 * reverted back to the stale, pre-adjustment figure.
 */
class OrderBreakdownProfitSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;
    private User $reseller;
    private Vendor $vendor;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'Pending', 2 => 'Processing'] as $id => $name) {
            OrderStatus::updateOrCreate(['id' => $id], ['name' => $name, 'type' => 'other', 'is_active' => true]);
        }

        $admin = User::factory()->create([
            'email' => 'admin_sync_test@test.com',
            'user_type' => 'admin',
            'role' => 'admin',
        ]);
        $this->adminToken = ApiTokenService::create($admin, ['basic', 'admin'], 60, 'admin-token')['plain'];

        $this->reseller = User::factory()->create([
            'email' => 'reseller_sync_test@test.com',
            'user_type' => 'reseller',
        ]);

        $vendorUser = User::factory()->create([
            'email' => 'vendor_sync_test@test.com',
            'user_type' => 'vendor',
        ]);

        $this->vendor = Vendor::create([
            'user_id' => $vendorUser->id,
            'shop_name' => 'Sync Test Shop',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'Fashion',
            'slug' => 'fashion-' . uniqid(),
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product-' . uniqid(),
            'sku' => 'TP-01',
            'unit_price' => 500.00,
            'admin_price' => 600.00,
            'price' => 800.00,
            'current_stock' => 50,
            'category_id' => $category->id,
            'vendor_id' => $this->vendor->id,
            'status' => 1,
        ]);
    }

    private function createOrderWithItem(): Order
    {
        $order = Order::create([
            'user_id' => $this->reseller->id,
            'order_number' => 'ORD-SYNC-' . uniqid(),
            'status' => 1,
            'payment_status' => 'unpaid',
            'customer_name' => 'John Doe',
            'customer_phone' => '01700000000',
            'shipping_address' => '123 Dhaka Road',
            'zone' => 'Dhaka',
            'subtotal' => 800.00,
            'reseller_price' => 800.00,
            'reseller_profit' => 200.00,
            'shipping_fee' => 100.00,
            'discount' => 0.00,
            'total' => 900.00,
            'remaining_cod' => 900.00,
            'vendor_id' => $this->vendor->id,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'shop_id' => $this->vendor->id,
            'product_name' => $this->product->name,
            'sku' => $this->product->sku,
            'unit_price' => 500.00,
            'admin_price' => 600.00,
            'reseller_price' => 800.00,
            'qty' => 1,
            'line_total' => 800.00,
            'line_total_reseller_profit' => 200.00,
            'status' => 1,
        ]);

        return $order;
    }

    public function test_breakdown_price_adjustment_updates_order_level_reseller_profit(): void
    {
        $order = $this->createOrderWithItem();

        // Admin raises the reseller selling price by 1500 (800 -> 2300) via the
        // Financial Stake Breakdown modal.
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/order-settlements/orders/{$order->id}/update-breakdown", [
                'reseller_price' => 2300.00,
            ]);

        $response->assertStatus(200);

        $order->refresh();
        $item = $order->items()->first();

        // Expected profit: 2300 - 600 (admin_price, unchanged) = 1700
        $this->assertEquals(2300.00, (float) $order->subtotal);
        $this->assertEquals(2300.00, (float) $order->reseller_price);
        $this->assertEquals(1700.00, (float) $order->reseller_profit);
        $this->assertEquals(2300.00, (float) $item->line_total);
        $this->assertEquals(1700.00, (float) $item->line_total_reseller_profit);
    }

    public function test_reseller_profit_survives_a_later_plain_order_update(): void
    {
        $order = $this->createOrderWithItem();

        // Step 1: admin adjusts the price via the breakdown modal.
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/order-settlements/orders/{$order->id}/update-breakdown", [
                'reseller_price' => 2300.00,
            ])->assertStatus(200);

        $order->refresh();
        $this->assertEquals(1700.00, (float) $order->reseller_profit);

        // Step 2: admin (or anyone) later edits an unrelated field (shipping fee)
        // through the plain order-update endpoint, without touching items.
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->putJson("/api/orders/update/{$order->id}", [
                'shipping_fee' => 250.00,
            ]);

        $response->assertStatus(200);

        $order->refresh();

        // reseller_profit must NOT be silently reverted to the pre-adjustment 200.00 -
        // this is exactly the admin-panel vs dropshipper-panel mismatch being fixed.
        $this->assertEquals(1700.00, (float) $order->reseller_profit);
        $this->assertEquals(2300.00, (float) $order->subtotal);
        $this->assertEquals(250.00, (float) $order->shipping_fee);
    }
}
