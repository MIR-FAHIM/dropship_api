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
 * Reproduces the admin-panel vs dropshipper-panel reseller_profit mismatch, and
 * verifies the fix: orders.reseller_profit is always re-derived from the single
 * authoritative cash-flow formula (collectable amount - admin base price -
 * shipping fee - COD fee), whether the price, shipping fee, discount or total
 * is changed via the Financial Stake Breakdown modal OR the plain order-update
 * endpoint - so it can never go stale or get silently reverted by either path.
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
            'banned' => 0,
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

    private function createOrderWithTwoEqualMarginItems(): Order
    {
        $order = Order::create([
            'user_id' => $this->reseller->id,
            'order_number' => 'ORD-SYNC2-' . uniqid(),
            'status' => 1,
            'payment_status' => 'unpaid',
            'customer_name' => 'Jane Doe',
            'customer_phone' => '01700000001',
            'shipping_address' => '123 Dhaka Road',
            'zone' => 'Dhaka',
            'subtotal' => 1400.00,
            'reseller_price' => 1400.00,
            'reseller_profit' => 400.00,
            'shipping_fee' => 100.00,
            'discount' => 0.00,
            'total' => 1500.00,
            'remaining_cod' => 1500.00,
            'vendor_id' => $this->vendor->id,
        ]);

        // Item A: margin 200 (800 - 600)
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

        // Item B: margin 200 (600 - 400)
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'shop_id' => $this->vendor->id,
            'product_name' => $this->product->name,
            'sku' => $this->product->sku,
            'unit_price' => 300.00,
            'admin_price' => 400.00,
            'reseller_price' => 600.00,
            'qty' => 1,
            'line_total' => 600.00,
            'line_total_reseller_profit' => 200.00,
            'status' => 1,
        ]);

        return $order;
    }

    public function test_multi_item_order_allocates_profit_proportionally_to_item_margin(): void
    {
        $order = $this->createOrderWithTwoEqualMarginItems();

        // Raise collectable amount by 1500 (1500 -> 3000), no item/shipping change.
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/order-settlements/orders/{$order->id}/update-breakdown", [
                'collectable_amount' => 3000.00,
            ]);
        $response->assertStatus(200);

        $order->refresh();

        // collectable (3000) - admin base price (600+400=1000) - shipping (100)
        // - default 1% COD fee on collectable (30.00) = 1870.00.
        $this->assertEquals(1870.00, (float) $order->reseller_profit);

        $items = $order->items()->orderBy('id')->get();
        $this->assertCount(2, $items);

        // Both items have equal pure margin (200 each), so they split the 1870.00
        // order-level profit evenly - 935.00 each - not their original 200.00 margin.
        $this->assertEquals(935.00, (float) $items[0]->line_total_reseller_profit);
        $this->assertEquals(935.00, (float) $items[1]->line_total_reseller_profit);
        $this->assertEquals(
            (float) $order->reseller_profit,
            round((float) $items[0]->line_total_reseller_profit + (float) $items[1]->line_total_reseller_profit, 2)
        );
    }

    public function test_breakdown_price_and_collectable_adjustment_updates_order_level_reseller_profit(): void
    {
        $order = $this->createOrderWithItem();

        // Admin raises both the reseller selling price (800 -> 2300) and the
        // collectable/COD amount (900 -> 2400) by 1500 via the Financial Stake
        // Breakdown modal, without touching shipping_fee (stays 100).
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/order-settlements/orders/{$order->id}/update-breakdown", [
                'reseller_price' => 2300.00,
                'collectable_amount' => 2400.00,
            ]);

        $response->assertStatus(200);

        $order->refresh();
        $item = $order->items()->first();

        // line_total (pure selling-price total) is unaffected by shipping/collectable.
        $this->assertEquals(2300.00, (float) $item->line_total);

        // Order-level reseller_profit uses the cash-flow formula:
        // collectable (2400) - admin base price (600) - shipping fee (100)
        // - default 1% COD fee on collectable (24.00) = 1676.00.
        $this->assertEquals(2300.00, (float) $order->subtotal);
        $this->assertEquals(2300.00, (float) $order->reseller_price);
        $this->assertEquals(2400.00, (float) $order->total);
        $this->assertEquals(1676.00, (float) $order->reseller_profit);

        // The single item takes its full allocated share of that order-level figure,
        // not the pure margin (2300-600=1700) - this is what the Sale & Profit page
        // (and anywhere else reading order_items.line_total_reseller_profit) now sees.
        $this->assertEquals(1676.00, (float) $item->line_total_reseller_profit);
    }

    public function test_raising_total_via_plain_order_update_also_moves_reseller_profit(): void
    {
        $order = $this->createOrderWithItem();
        $this->assertEquals(200.00, (float) $order->reseller_profit);

        // Admin raises shipping_fee by 1500 (100 -> 1600) through the plain
        // order-update endpoint (no breakdown modal, no item changes). Subtotal
        // stays 800, so total must become 800 + 1600 - 0 = 2400.
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->putJson("/api/orders/update/{$order->id}", [
                'shipping_fee' => 1600.00,
                'total' => 2400.00,
            ]);

        $response->assertStatus(200);

        $order->refresh();
        $this->assertEquals(1600.00, (float) $order->shipping_fee);
        $this->assertEquals(2400.00, (float) $order->total);
        $this->assertEquals(2400.00, (float) $order->remaining_cod);

        // reseller_profit must move with it (this used to stay frozen at 200.00,
        // which was the admin-panel vs dropshipper-panel mismatch being fixed):
        // collectable (2400) - admin base price (600) - shipping fee (1600)
        // - default 1% COD fee on collectable (24.00) = 176.00.
        $this->assertNotEquals(200.00, (float) $order->reseller_profit);
        $this->assertEquals(176.00, (float) $order->reseller_profit);

        // order_items.line_total_reseller_profit (single item -> full share) moves too.
        $item = $order->items()->first();
        $this->assertEquals(176.00, (float) $item->line_total_reseller_profit);
    }
}
