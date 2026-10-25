<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\Shops;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $vendorUser;

    private Vendor $vendor;

    private Vendor $otherVendor;

    private Product $product;

    private Product $otherProduct;

    private OrderStatus $completed;

    private OrderStatus $pending;

    private Order $mixedOrder;

    private Order $secondOrder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['user_type' => 'customer', 'role' => 'admin', 'banned' => 0]);
        $this->vendorUser = User::factory()->create(['user_type' => 'vendor', 'banned' => 0]);
        $this->vendor = Vendor::create(['user_id' => $this->vendorUser->id, 'shop_name' => 'First Store']);
        $this->otherVendor = Vendor::create([
            'user_id' => User::factory()->create(['user_type' => 'vendor'])->id,
            'shop_name' => 'Second Store',
        ]);
        // Deliberately swap shops.id versus vendors.id to detect unsafe ID assumptions.
        Shops::create(['user_id' => $this->otherVendor->user_id, 'name' => 'Second Profile']);
        Shops::create(['user_id' => $this->vendorUser->id, 'name' => 'First Profile']);
        Shops::create(['user_id' => $this->vendorUser->id, 'name' => 'Additional Profile']);
        $category = Category::create(['name' => 'Hardware', 'slug' => 'hardware']);
        $this->product = $this->product($category, 'Drill', $this->vendor);
        $this->otherProduct = $this->product($category, 'Saw', $this->otherVendor);
        $this->completed = OrderStatus::create(['name' => 'Completed', 'type' => 'completed', 'is_active' => true]);
        $this->pending = OrderStatus::create(['name' => 'Pending', 'type' => 'pending', 'is_active' => true]);

        $this->mixedOrder = $this->order('2026-10-24 23:59:59', $this->completed, 'paid', 4525, 300);
        $this->item($this->mixedOrder, $this->product, $this->vendor, 2, 150, 300, 100);
        $this->item($this->mixedOrder, $this->product, $this->vendor, 1, 175, 175, 120);
        $this->item($this->mixedOrder, $this->otherProduct, $this->otherVendor, 4, 1000, 4000, 800);
        // Same product fulfilled by another vendor: admin sees it, first vendor must not.
        $this->item($this->mixedOrder, $this->product, $this->otherVendor, 1, 220, 220, 160);
        $this->secondOrder = $this->order('2026-10-25 00:00:00', $this->pending, 'unpaid', 330);
        $this->item($this->secondOrder, $this->product, $this->vendor, 2, 100, 200, 80);
    }

    private function product(Category $category, string $name, Vendor $vendor): Product
    {
        return Product::create([
            'category_id' => $category->id, 'vendor_id' => $vendor->id,
            'name' => $name, 'slug' => strtolower($name), 'sku' => strtoupper($name),
            'unit_price' => 9999, 'admin_price' => 11000,
        ]);
    }

    private function order(string $date, OrderStatus $status, string $payment, float $total, float $discount = 0): Order
    {
        $order = Order::create([
            'order_number' => 'ORD-'.uniqid(), 'user_id' => $this->admin->id,
            'status' => $status->id, 'payment_status' => $payment,
            'subtotal' => $total - 130 + $discount, 'shipping_fee' => 130,
            'total' => $total, 'discount' => $discount, 'vendor_id' => $this->otherVendor->id,
        ]);
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $date, 'updated_at' => $date]);

        return $order;
    }

    private function item(Order $order, Product $product, Vendor $vendor, int $qty, float $sellingPrice, float $lineTotal, float $unitPrice): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'shop_id' => $vendor->id,
            'product_name' => 'Snapshot '.$product->name, 'sku' => $product->sku,
            'qty' => $qty, 'unit_price' => $unitPrice, 'admin_price' => $unitPrice + 5,
            'reseller_price' => $sellingPrice, 'line_total' => $lineTotal, 'status' => 'delivered',
        ]);
    }

    private function headers(User $user): array
    {
        $plain = 'history-'.uniqid();
        ApiToken::create([
            'user_id' => $user->id, 'token_hash' => hash('sha256', $plain),
            'is_revoked' => false, 'expires_at' => now()->addDay(),
        ]);

        return ['Authorization' => 'Bearer '.$plain];
    }

    public function test_admin_groups_products_without_counting_items_or_whole_order_totals_twice(): void
    {
        $response = $this->getJson('/api/admin/orders/history', $this->headers($this->admin));
        $response->assertOk()->assertJsonPath('status', 'success')->assertJsonCount(2, 'data.groups.data');
        $group = collect($response->json('data.groups.data'))->firstWhere('product_id', $this->product->id);
        $this->assertSame(['order_count' => 2, 'item_count' => 4, 'total_quantity' => 6, 'total_product_sales' => 895], $group['totals']);
        $this->assertCount(2, $group['shops']);
        $this->assertCount(2, collect($group['shops'])->firstWhere('id', $this->vendor->id)['shop_profiles']);
        $this->assertSame('not_available', $response->json('data.discount_allocation'));
        $this->assertStringContainsString('product_id='.$this->product->id, $group['history_url']);
    }

    public function test_selected_product_embeds_paginated_orders_and_only_matching_item_snapshots(): void
    {
        $response = $this->getJson('/api/admin/orders/history?product_id='.$this->product->id.'&history_per_page=1&history_page=2', $this->headers($this->admin));
        $response->assertOk()->assertJsonPath('data.groups.total', 1)
            ->assertJsonPath('data.orders.total', 2)->assertJsonPath('data.orders.current_page', 2)
            ->assertJsonCount(1, 'data.orders.data')->assertJsonPath('data.orders.data.0.order_id', $this->mixedOrder->id)
            ->assertJsonPath('data.orders.data.0.quantity', 4)->assertJsonPath('data.orders.data.0.subtotal', 695)
            ->assertJsonPath('data.orders.data.0.order_status.name', 'Completed')
            ->assertJsonPath('data.orders.data.0.payment_status', 'paid')->assertJsonCount(3, 'data.orders.data.0.items')
            ->assertJsonPath('data.orders.data.0.items.0.unit_price', 100)
            ->assertJsonPath('data.orders.data.0.items.0.product_name', 'Snapshot Drill');
        $this->assertArrayNotHasKey('total', $response->json('data.orders.data.0'));
        $this->assertStringContainsString('history_page=1', $response->json('data.orders.prev_page_url'));
    }

    public function test_vendor_isolation_uses_item_fulfiller_not_order_header_product_owner_or_shop_profile_id(): void
    {
        $this->product->update(['vendor_id' => $this->otherVendor->id]);
        $response = $this->getJson('/api/vendor/orders/history?product_id='.$this->product->id, $this->headers($this->vendorUser));
        $response->assertOk()->assertJsonPath('data.grouped_by', ['product_id', 'shop_id'])
            ->assertJsonCount(1, 'data.groups.data')->assertJsonPath('data.groups.data.0.shop_id', $this->vendor->id)
            ->assertJsonPath('data.groups.data.0.totals.total_product_sales', 675)
            ->assertJsonPath('data.groups.data.0.totals.total_quantity', 5)
            ->assertJsonPath('data.groups.data.0.totals.order_count', 2)
            ->assertJsonPath('data.groups.data.0.shop.shop_profiles.0.name', 'First Profile');
        foreach ($response->json('data.orders.data') as $order) {
            foreach ($order['items'] as $item) {
                $this->assertSame($this->vendor->id, $item['shop_id']);
                $this->assertSame($this->product->id, $item['product_id']);
            }
        }
    }

    public function test_vendor_cannot_request_foreign_shop_or_supply_vendor_identity(): void
    {
        $headers = $this->headers($this->vendorUser);
        foreach (['/api/vendor/orders/history', '/api/vendor/orders/history/orders?product_id='.$this->product->id] as $url) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $this->getJson($url.$separator.'shop_id='.$this->otherVendor->id, $headers)->assertUnprocessable()->assertJsonValidationErrors('shop_id');
            $this->getJson($url.$separator.'vendor_id='.$this->otherVendor->id, $headers)->assertUnprocessable()->assertJsonValidationErrors('vendor_id');
        }
        $this->getJson('/api/vendor/orders/history?product_id='.$this->otherProduct->id, $headers)
            ->assertOk()->assertJsonPath('data.groups.total', 0)->assertJsonPath('data.orders.total', 0);
    }

    public function test_vendor_history_orders_are_paginated_once_per_order_with_relevant_items_only(): void
    {
        $response = $this->getJson('/api/vendor/orders/history/orders?product_id='.$this->product->id.'&shop_id='.$this->vendor->id.'&per_page=1&page=2', $this->headers($this->vendorUser));
        $response->assertOk()->assertJsonPath('data.orders.total', 2)->assertJsonPath('data.orders.current_page', 2)
            ->assertJsonPath('data.orders.data.0.order_id', $this->mixedOrder->id)
            ->assertJsonPath('data.orders.data.0.quantity', 3)->assertJsonPath('data.orders.data.0.subtotal', 475)
            ->assertJsonCount(2, 'data.orders.data.0.items');
    }

    public function test_filters_apply_before_aggregation_with_inclusive_date_bounds(): void
    {
        $headers = $this->headers($this->admin);
        $response = $this->getJson('/api/admin/orders/history?product_id='.$this->product->id.'&date_from=2026-10-24&date_to=2026-10-24&status='.$this->completed->id.'&payment_status=paid', $headers);
        $response->assertOk()->assertJsonPath('data.groups.data.0.totals.total_product_sales', 695)
            ->assertJsonPath('data.groups.data.0.totals.order_count', 1)->assertJsonPath('data.orders.total', 1);
        $this->getJson('/api/admin/orders/history?date_to=2026-10-24', $headers)->assertOk()->assertJsonPath('data.groups.total', 2);
        $this->getJson('/api/admin/orders/history/orders?product_id='.$this->product->id.'&date_from=2026-10-25', $headers)
            ->assertOk()->assertJsonPath('data.orders.total', 1)->assertJsonPath('data.orders.data.0.order_id', $this->secondOrder->id);
        $this->getJson('/api/admin/orders/history?shop_id='.$this->otherVendor->id, $headers)
            ->assertOk()->assertJsonCount(2, 'data.groups.data');
    }

    public function test_group_pagination_does_not_limit_totals_and_has_stable_metadata(): void
    {
        $headers = $this->headers($this->admin);
        $this->getJson('/api/admin/orders/history?per_page=1', $headers)
            ->assertOk()->assertJsonPath('data.groups.total', 2)->assertJsonPath('data.groups.last_page', 2)
            ->assertJsonPath('data.groups.data.0.product_id', $this->product->id)
            ->assertJsonPath('data.groups.data.0.totals.total_product_sales', 895);
        $this->getJson('/api/admin/orders/history?per_page=1&page=2', $headers)
            ->assertOk()->assertJsonPath('data.groups.data.0.product_id', $this->otherProduct->id)
            ->assertJsonPath('data.groups.data.0.totals.total_product_sales', 4000);
    }

    public function test_authentication_and_panel_roles_are_required_on_all_endpoints(): void
    {
        $customer = User::factory()->create(['user_type' => 'customer', 'role' => 'dropshipper']);
        foreach (['admin', 'vendor'] as $panel) {
            foreach (['', '/orders?product_id='.$this->product->id] as $suffix) {
                $url = '/api/'.$panel.'/orders/history'.$suffix;
                $this->getJson($url)->assertUnauthorized();
                $this->getJson($url, $this->headers($customer))->assertForbidden();
            }
        }
        $this->getJson('/api/admin/orders/history', $this->headers($this->vendorUser))->assertForbidden();
        $this->getJson('/api/vendor/orders/history', $this->headers($this->admin))->assertForbidden();
        $unlinkedVendor = User::factory()->create(['user_type' => 'vendor']);
        $this->getJson('/api/vendor/orders/history', $this->headers($unlinkedVendor))->assertForbidden();
    }

    public function test_invalid_filters_and_missing_product_for_expansion_are_rejected(): void
    {
        $headers = $this->headers($this->admin);
        foreach ([
            'product_id' => '99999', 'shop_id' => '99999', 'status' => '99999',
            'payment_status' => 'invented', 'per_page' => '101', 'page' => '0',
            'history_page' => '-1', 'history_per_page' => '101', 'date_from' => 'not-a-date',
        ] as $field => $value) {
            $this->getJson('/api/admin/orders/history?'.$field.'='.$value, $headers)
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->getJson('/api/admin/orders/history?date_from=2026-10-25&date_to=2026-10-24', $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->getJson('/api/admin/orders/history/orders', $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('product_id');
    }

    public function test_deleted_products_keep_their_order_item_history(): void
    {
        $productId = $this->product->id;
        $this->product->delete();
        $headers = $this->headers($this->vendorUser);
        $response = $this->getJson('/api/vendor/orders/history?unlinked_product=1', $headers);
        $response->assertOk()->assertJsonPath('data.groups.data.0.product_id', null)
            ->assertJsonPath('data.groups.data.0.product', null)
            ->assertJsonPath('data.groups.data.0.product_name', 'Snapshot Drill')
            ->assertJsonPath('data.groups.data.0.totals.total_product_sales', 675)
            ->assertJsonPath('data.orders.total', 2);
        $this->assertSame(0, OrderItem::where('product_id', $productId)->count());
        $this->getJson($response->json('data.groups.data.0.history_url'), $headers)
            ->assertOk()->assertJsonPath('data.orders.total', 2);
    }

    public function test_group_query_count_is_bounded_as_product_groups_increase(): void
    {
        $headers = $this->headers($this->admin);
        $countQueries = function () use ($headers): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/admin/orders/history', $headers)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $before = $countQueries();
        $category = Category::firstOrFail();
        for ($i = 0; $i < 8; $i++) {
            $product = $this->product($category, 'Tool'.$i, $this->vendor);
            $this->item($this->mixedOrder, $product, $this->vendor, 1, 50, 50, 40);
        }
        $this->assertLessThanOrEqual($before, $countQueries());
    }

    public function test_expanded_order_query_count_does_not_grow_per_order(): void
    {
        $headers = $this->headers($this->vendorUser);
        $countQueries = function () use ($headers): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/vendor/orders/history/orders?product_id='.$this->product->id, $headers)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $before = $countQueries();
        for ($i = 0; $i < 8; $i++) {
            $order = $this->order('2026-10-25 10:00:00', $this->completed, 'paid', 180);
            $this->item($order, $this->product, $this->vendor, 1, 50, 50, 40);
        }
        $this->assertLessThanOrEqual($before, $countQueries());
    }

    public function test_decimal_sales_are_summed_from_snapshots_and_rounded_to_two_places(): void
    {
        $product = $this->product(Category::firstOrFail(), 'Screw', $this->vendor);
        $this->item($this->mixedOrder, $product, $this->vendor, 1, 10.10, 10.10, 5.01);
        $this->item($this->secondOrder, $product, $this->vendor, 3, 20.20, 60.60, 15.01);
        $this->getJson('/api/vendor/orders/history?product_id='.$product->id, $this->headers($this->vendorUser))
            ->assertOk()->assertJsonPath('data.groups.data.0.totals.total_product_sales', 70.7)
            ->assertJsonPath('data.groups.data.0.totals.total_quantity', 4)
            ->assertJsonPath('data.orders.data.0.subtotal', 60.6)
            ->assertJsonPath('data.orders.data.0.items.0.unit_price', 15.01);
    }

    public function test_expired_and_revoked_tokens_cannot_access_history(): void
    {
        $headers = $this->headers($this->vendorUser);
        ApiToken::where('user_id', $this->vendorUser->id)->update(['expires_at' => now()->subDay()]);
        $this->getJson('/api/vendor/orders/history', $headers)->assertUnauthorized();
        $headers = $this->headers($this->vendorUser);
        ApiToken::where('user_id', $this->vendorUser->id)->update(['is_revoked' => true]);
        $this->getJson('/api/vendor/orders/history/orders?product_id='.$this->product->id, $headers)->assertUnauthorized();
    }

    public function test_admin_group_wise_history_groups_by_both_vendor_and_product(): void
    {
        $headers = $this->headers($this->admin);

        // 1. Group-wise order history groups across BOTH product_id and shop_id (vendor)
        $response = $this->getJson('/api/admin/orders/history?group_by=vendor_product', $headers);
        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.grouped_by', ['product_id', 'shop_id'])
            ->assertJsonCount(3, 'data.groups.data');

        $groups = collect($response->json('data.groups.data'));

        // Same product fulfilled by First Store
        $firstStoreProduct = $groups->first(fn ($g) => $g['product_id'] === $this->product->id && $g['shop_id'] === $this->vendor->id);
        $this->assertNotNull($firstStoreProduct);
        $this->assertSame(5, $firstStoreProduct['totals']['total_quantity']);
        $this->assertSame(2, $firstStoreProduct['totals']['order_count']);
        $this->assertEquals(675, $firstStoreProduct['totals']['total_product_sales']);
        $this->assertSame('First Store', $firstStoreProduct['shop']['name']);
        $this->assertSame($this->vendor->id, $firstStoreProduct['vendor_id']);

        // Same product fulfilled by Second Store
        $secondStoreProduct = $groups->first(fn ($g) => $g['product_id'] === $this->product->id && $g['shop_id'] === $this->otherVendor->id);
        $this->assertNotNull($secondStoreProduct);
        $this->assertSame(1, $secondStoreProduct['totals']['total_quantity']);
        $this->assertSame(1, $secondStoreProduct['totals']['order_count']);
        $this->assertEquals(220, $secondStoreProduct['totals']['total_product_sales']);
        $this->assertSame('Second Store', $secondStoreProduct['shop']['name']);

        // Other product fulfilled by Second Store
        $secondStoreOther = $groups->first(fn ($g) => $g['product_id'] === $this->otherProduct->id && $g['shop_id'] === $this->otherVendor->id);
        $this->assertNotNull($secondStoreOther);
        $this->assertSame(4, $secondStoreOther['totals']['total_quantity']);
        $this->assertSame(1, $secondStoreOther['totals']['order_count']);
        $this->assertEquals(4000, $secondStoreOther['totals']['total_product_sales']);

        // 2. Filter by vendor_id directly works for admin
        $filtered = $this->getJson('/api/admin/orders/history?group_by=vendor_product&vendor_id='.$this->vendor->id, $headers);
        $filtered->assertOk()->assertJsonCount(1, 'data.groups.data')
            ->assertJsonPath('data.groups.data.0.shop_id', $this->vendor->id)
            ->assertJsonPath('data.groups.data.0.product_id', $this->product->id);

        // 3. Expanding the First Store product group fetches only First Store orders
        $ordersResponse = $this->getJson('/api/admin/orders/history/orders?product_id='.$this->product->id.'&shop_id='.$this->vendor->id, $headers);
        $ordersResponse->assertOk()->assertJsonPath('data.orders.total', 2);
        foreach ($ordersResponse->json('data.orders.data') as $order) {
            foreach ($order['items'] as $item) {
                $this->assertSame($this->vendor->id, $item['shop_id']);
                $this->assertSame($this->product->id, $item['product_id']);
            }
        }
    }
}

