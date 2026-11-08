<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\CarryBeeOrderCreateForm;
use App\Models\Category;
use App\Models\DeliveryAssignedInfo;
use App\Models\DeliveryInformation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemChangeLog;
use App\Models\OrderSettlement;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderEditingAndConsignmentLockTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $adminToken;
    private User $reseller;
    private Vendor $vendor;
    private Product $product1;
    private Product $product2;
    private ProductAttribute $attr1;
    private ProductAttribute $attr2;

    protected function setUp(): void
    {
        parent::setUp();

        for ($i = 1; $i <= 10; $i++) {
            OrderStatus::updateOrCreate(
                ['id' => $i],
                [
                    'name' => match ($i) {
                        1 => 'Pending',
                        2 => 'Processing',
                        9 => 'Delivered',
                        10 => 'Cancelled',
                        default => "Status {$i}",
                    },
                    'type' => match ($i) {
                        1 => 'pending',
                        2 => 'processing',
                        9 => 'delivered',
                        10 => 'cancelled',
                        default => 'other',
                    },
                    'is_active' => true,
                ]
            );
        }

        $this->admin = User::factory()->create([
            'email' => 'admin_test@test.com',
            'user_type' => 'admin',
            'role' => 'admin',
            'banned' => 0,
        ]);

        $this->adminToken = ApiTokenService::create($this->admin, ['basic', 'admin'], 60, 'admin-token')['plain'];

        $this->reseller = User::factory()->create([
            'email' => 'reseller_test@test.com',
            'user_type' => 'reseller',
        ]);

        $vendorUser = User::factory()->create([
            'email' => 'vendor_test@test.com',
            'user_type' => 'vendor',
        ]);

        $this->vendor = Vendor::create([
            'user_id' => $vendorUser->id,
            'shop_name' => 'Lock Test Shop',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'Fashion',
            'slug' => 'fashion-' . uniqid(),
            'is_active' => true,
        ]);

        $this->product1 = Product::create([
            'name' => 'Premium Shirt',
            'slug' => 'premium-shirt-' . uniqid(),
            'sku' => 'SHIRT-01',
            'unit_price' => 500.00,
            'admin_price' => 600.00,
            'price' => 800.00,
            'current_stock' => 50,
            'category_id' => $category->id,
            'vendor_id' => $this->vendor->id,
            'status' => 1,
        ]);

        $this->product2 = Product::create([
            'name' => 'Denim Pants',
            'slug' => 'denim-pants-' . uniqid(),
            'sku' => 'PANTS-01',
            'unit_price' => 700.00,
            'admin_price' => 800.00,
            'price' => 1100.00,
            'current_stock' => 50,
            'category_id' => $category->id,
            'vendor_id' => $this->vendor->id,
            'status' => 1,
        ]);

        $colorAttr = Attribute::create(['name' => 'Color']);
        $sizeAttr = Attribute::create(['name' => 'Size']);
        $redVal = AttributeValue::create(['attribute_id' => $colorAttr->id, 'value' => 'Red']);
        $blueVal = AttributeValue::create(['attribute_id' => $colorAttr->id, 'value' => 'Blue']);

        $this->attr1 = ProductAttribute::create([
            'product_id' => $this->product1->id,
            'attribute_id' => $colorAttr->id,
            'attribute_value_id' => $redVal->id,
        ]);

        $this->attr2 = ProductAttribute::create([
            'product_id' => $this->product1->id,
            'attribute_id' => $colorAttr->id,
            'attribute_value_id' => $blueVal->id,
        ]);
    }

    private function authGetJson(string $uri)
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)->getJson($uri);
    }

    private function authPutJson(string $uri, array $data = [])
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)->putJson($uri, $data);
    }

    private function createTestOrder(bool $withConsignment = false): Order
    {
        $order = Order::create([
            'user_id' => $this->reseller->id,
            'order_number' => 'ORD-EDIT-' . uniqid(),
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
            'product_id' => $this->product1->id,
            'shop_id' => $this->vendor->id,
            'attribute_id' => $this->attr1->id,
            'product_name' => $this->product1->name,
            'sku' => $this->product1->sku,
            'unit_price' => 500.00,
            'admin_price' => 600.00,
            'reseller_price' => 800.00,
            'qty' => 1,
            'line_total' => 800.00,
            'line_total_reseller_profit' => 200.00,
            'status' => 1,
        ]);

        if ($withConsignment) {
            DeliveryAssignedInfo::create([
                'order_id' => $order->id,
                'consignment_id' => 'CONS-TEST-999',
                'delivery_company_id' => 1,
                'recipient_name' => 'John Doe',
                'recipient_phone' => '01700000000',
                'recipient_address' => '123 Dhaka Road',
                'collectable_amount' => 900.00,
            ]);
        }

        return $order;
    }

    public function test_can_get_order_details_with_consignment_and_editable_flags(): void
    {
        $order = $this->createTestOrder(false);

        $response = $this->authGetJson("/api/orders/details/{$order->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'data' => [
                'id' => $order->id,
                'has_consignment' => false,
                'is_editable' => true,
                'consignment_id' => null,
            ],
        ]);
    }

    public function test_can_update_order_without_consignment(): void
    {
        $order = $this->createTestOrder(false);
        $item = $order->items->first();

        $payload = [
            'customer_name' => 'Updated Customer',
            'customer_phone' => '01899999999',
            'shipping_address' => '456 Chittagong High St',
            'shipping_fee' => 120.00,
            'discount' => 50.00,
            'items' => [
                [
                    'id' => $item->id,
                    'product_id' => $this->product1->id,
                    'qty' => 2,
                    'reseller_price' => 850.00,
                    'admin_price' => 600.00,
                    'attribute_id' => $this->attr2->id,
                ],
            ],
            // Expected: subtotal = 2 * 850 = 1700, total = 1700 + 120 - 50 = 1770
            'total' => 1770.00,
        ];

        $response = $this->authPutJson("/api/orders/update/{$order->id}", $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Order updated successfully',
        ]);

        $order->refresh();
        $this->assertEquals('Updated Customer', $order->customer_name);
        $this->assertEquals('01899999999', $order->customer_phone);
        $this->assertEquals(1700.00, (float) $order->subtotal);
        $this->assertEquals(120.00, (float) $order->shipping_fee);
        $this->assertEquals(50.00, (float) $order->discount);
        $this->assertEquals(1770.00, (float) $order->total);
        // Order-level reseller_profit uses the cash-flow breakdown formula:
        // collectable (remaining_cod = total = 1770) - admin base price (2*600=1200)
        // - shipping fee (120) - default 1% COD fee on collectable (17.70) = 432.30.
        $this->assertEquals(432.30, (float) $order->reseller_profit);

        $updatedItem = $order->items()->first();
        $this->assertEquals(2, $updatedItem->qty);
        $this->assertEquals(850.00, (float) $updatedItem->reseller_price);
        // Single-item order, so it takes the item's full allocated share of the
        // order-level profit above (not the pure margin of 2*(850-600)=500).
        $this->assertEquals(432.30, (float) $updatedItem->line_total_reseller_profit);
        $this->assertEquals($this->attr2->id, $updatedItem->attribute_id);
    }

    public function test_cannot_update_order_when_consignment_exists_in_delivery_assigned_infos(): void
    {
        $order = $this->createTestOrder(true);

        $payload = [
            'customer_name' => 'Malicious Update',
            'total' => 900.00,
        ];

        $response = $this->authPutJson("/api/orders/update/{$order->id}", $payload);

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'failed',
            'errors' => [
                'locked' => true,
                'consignment_id' => 'CONS-TEST-999',
            ],
        ]);

        $order->refresh();
        $this->assertEquals('John Doe', $order->customer_name);
    }

    public function test_cannot_update_order_when_webhook_event_consignment_exists(): void
    {
        $order = $this->createTestOrder(false);
        \App\Models\CarrybeeWebhookEvent::create([
            'fingerprint' => 'fp_' . uniqid(),
            'order_id' => $order->id,
            'consignment_id' => 'WEBHOOK-CONS-123',
            'event' => 'order_created',
            'payload' => ['test' => true],
        ]);

        $payload = [
            'customer_name' => 'Should Not Change',
        ];

        $response = $this->authPutJson("/api/orders/update/{$order->id}", $payload);

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'failed',
            'errors' => [
                'locked' => true,
                'consignment_id' => 'WEBHOOK-CONS-123',
            ],
        ]);
    }

    public function test_update_rejected_if_submitted_total_does_not_match_recalculated_total(): void
    {
        $order = $this->createTestOrder(false);
        $item = $order->items->first();

        $payload = [
            'shipping_fee' => 100.00,
            'discount' => 0.00,
            'items' => [
                [
                    'id' => $item->id,
                    'product_id' => $this->product1->id,
                    'qty' => 1,
                    'reseller_price' => 800.00,
                    'admin_price' => 600.00,
                ],
            ],
            // Correct total is 900, but client submits 500
            'total' => 500.00,
        ];

        $response = $this->authPutJson("/api/orders/update/{$order->id}", $payload);

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'failed',
        ]);
        $this->assertStringContainsString('Total order amount mismatch', $response->json('message'));
    }

    public function test_update_rejected_if_reseller_price_less_than_admin_price(): void
    {
        $order = $this->createTestOrder(false);
        $item = $order->items->first();

        $payload = [
            'items' => [
                [
                    'id' => $item->id,
                    'product_id' => $this->product1->id,
                    'qty' => 1,
                    'reseller_price' => 500.00, // Less than admin_price 600
                    'admin_price' => 600.00,
                ],
            ],
        ];

        $response = $this->authPutJson("/api/orders/update/{$order->id}", $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString('cannot be less than admin base price', $response->json('message'));
    }

    public function test_update_rejected_if_discount_exceeds_subtotal_plus_shipping(): void
    {
        $order = $this->createTestOrder(false);
        $item = $order->items->first();

        $payload = [
            'shipping_fee' => 100.00,
            'discount' => 1000.00, // subtotal is 800, subtotal + shipping is 900
            'items' => [
                [
                    'id' => $item->id,
                    'product_id' => $this->product1->id,
                    'qty' => 1,
                    'reseller_price' => 800.00,
                    'admin_price' => 600.00,
                ],
            ],
        ];

        $response = $this->authPutJson("/api/orders/update/{$order->id}", $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString('Discount cannot exceed the subtotal plus shipping fee', $response->json('message'));
    }

    public function test_update_rejected_if_attribute_id_does_not_belong_to_product(): void
    {
        $order = $this->createTestOrder(false);
        $item = $order->items->first();

        // Create an attribute for product2
        $otherAttr = ProductAttribute::create([
            'product_id' => $this->product2->id,
            'attribute_id' => $this->attr1->attribute_id,
            'attribute_value_id' => $this->attr1->attribute_value_id,
        ]);

        $payload = [
            'items' => [
                [
                    'id' => $item->id,
                    'product_id' => $this->product1->id,
                    'qty' => 1,
                    'reseller_price' => 800.00,
                    'admin_price' => 600.00,
                    'attribute_id' => $otherAttr->id, // belongs to product2!
                ],
            ],
        ];

        $response = $this->authPutJson("/api/orders/update/{$order->id}", $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString('Selected product attribute does not belong to this product', $response->json('message'));
    }

    public function test_carrybee_order_create_rejected_if_order_already_has_consignment(): void
    {
        $order = $this->createTestOrder(true);

        $payload = [
            'order_id' => $order->id,
            'store_id' => 123,
            'merchant_order_id' => 'M-ORD-123',
            'delivery_type' => 1,
            'product_type' => 1,
            'recipient_phone' => '01700000000',
            'recipient_name' => 'John Doe',
            'recipient_address' => '123 Dhaka Road',
            'city_id' => 1,
            'zone_id' => 1,
            'item_weight' => 0.5,
            'item_quantity' => 1,
            'collectable_amount' => 900.00,
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/delivery-companies/carrybee/{$this->vendor->id}/orders", $payload);

        $response->assertStatus(409);
        $this->assertStringContainsString('already been created', $response->json('message'));
    }

    private function replacementItems(Order $order, array $overrides = []): array
    {
        $item = $order->items()->firstOrFail();

        return ['items' => [array_replace([
            'id' => $item->id,
            'expected_product_id' => $item->product_id,
            'product_id' => $this->product2->id,
            'qty' => $item->qty,
            'reseller_price' => 1000,
        ], $overrides)]];
    }

    public function test_replacement_updates_both_vendor_references_sku_prices_and_booking_in_place(): void
    {
        $order = $this->createTestOrder();
        $order->update(['status' => 3, 'discount' => 25, 'advance_amount' => 50, 'advance_status' => 'approved', 'advance_trx_id' => 'ADVANCE-123']);
        $vendor = Vendor::create([
            'user_id' => User::factory()->create()->id, 'shop_name' => 'Replacement Vendor',
            'is_active' => true, 'has_own_delivery_account' => true,
        ]);
        $this->product2->update(['vendor_id' => $vendor->id]);
        $order->items()->update(['status' => 'ready']);
        $item = $order->items()->firstOrFail();
        CarryBeeOrderCreateForm::create([
            'order_id' => $order->id, 'store_id' => 'old-store', 'merchant_order_id' => $order->order_number,
            'delivery_type' => 1, 'product_type' => 1, 'recipient_phone' => '01700000000',
            'recipient_name' => 'Customer', 'recipient_address' => 'Dhaka', 'city_id' => 1, 'zone_id' => 1,
            'area_id' => 1, 'item_weight' => 500, 'item_quantity' => 1, 'collectable_amount' => 900,
        ]);
        foreach (['courier_clearing' => [900, 0], 'order_revenue_clearing' => [0, 900], 'cash_platform' => [50, 0]] as $account => [$debit, $credit]) {
            $order->accountingLedgers()->create(['entry_type' => 'booking', 'account_code' => $account, 'debit' => $debit, 'credit' => $credit]);
        }

        $payload = $this->replacementItems($order, [
            'shop_id' => $this->vendor->id, 'sku' => 'STALE-SKU', 'product_name' => 'Wrong Name',
            'unit_price' => 1, 'admin_price' => 2,
        ]) + ['payment_status' => 'paid', 'advance_amount' => 0, 'advance_status' => 'none'];
        $this->authPutJson("/api/orders/update/{$order->id}", $payload)
            ->assertOk()->assertJsonPath('data.vendor.id', $vendor->id)
            ->assertJsonPath('data.items.0.id', $item->id)
            ->assertJsonPath('data.items.0.sku', 'PANTS-01');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'order_number' => $order->order_number, 'vendor_id' => $vendor->id,
            'status' => 2, 'payment_status' => 'unpaid', 'subtotal' => 1000, 'reseller_price' => 1000,
            'total' => 1075, 'remaining_cod' => 1075, 'reseller_profit' => 164.25, 'courier_account_owner' => 'vendor',
            'advance_amount' => 50, 'advance_status' => 'approved', 'advance_trx_id' => 'ADVANCE-123',
        ]);
        $this->assertDatabaseHas('order_items', [
            'id' => $item->id, 'order_id' => $order->id, 'product_id' => $this->product2->id,
            'shop_id' => $vendor->id, 'sku' => 'PANTS-01', 'product_name' => 'Denim Pants',
            'attribute_id' => null, 'status' => 1, 'unit_price' => 700, 'admin_price' => 800,
            'reseller_price' => 1000, 'qty' => 1, 'line_total' => 1000, 'line_total_reseller_profit' => 164.25,
        ]);
        $log = OrderItemChangeLog::where('order_item_id', $item->id)->sole();
        $this->assertEquals($this->admin->id, $log->admin_id);
        $this->assertSame('product_replaced', $log->action);
        $this->assertEquals('SHIRT-01', $log->previous_values['sku']);
        $this->assertEquals($this->vendor->id, $log->previous_values['shop_id']);
        $this->assertEquals($this->attr1->id, $log->previous_values['attribute_id']);
        $this->assertEquals(500, $log->previous_values['unit_price']);
        $this->assertEquals('PANTS-01', $log->new_values['sku']);
        $this->assertEquals($vendor->id, $log->new_values['shop_id']);
        $this->assertEquals(700, $log->new_values['unit_price']);
        $this->assertEquals(164.25, $log->new_values['line_total_reseller_profit']);
        $this->assertSame(1, $order->items()->count());
        $this->assertDatabaseMissing('carry_bee_order_create_forms', ['order_id' => $order->id]);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'status_id' => 2, 'changed_by' => $this->admin->id]);
        $this->assertDatabaseHas('order_accounting_ledgers', ['order_id' => $order->id, 'account_code' => 'courier_clearing', 'debit' => 1075]);
        $this->assertDatabaseHas('order_accounting_ledgers', ['order_id' => $order->id, 'account_code' => 'order_revenue_clearing', 'credit' => 1075]);
        $this->assertDatabaseHas('order_accounting_ledgers', ['order_id' => $order->id, 'account_code' => 'cash_platform', 'debit' => 50]);
        $this->assertSame(50, (int) $this->product1->fresh()->current_stock);
        $this->assertSame(50, (int) $this->product2->fresh()->current_stock);
        $this->authGetJson("/api/orders/vendor/{$vendor->id}")->assertOk()->assertJsonPath('data.data.0.id', $order->id);
        $this->authGetJson("/api/orders/vendor/{$this->vendor->id}")->assertNotFound();
    }

    public function test_replacement_catalog_can_find_products_by_sku_and_omits_unavailable_products(): void
    {
        $this->product1->update(['current_stock' => 0]);
        $this->authGetJson('/api/orders/replacement-products?search=PANTS-01')
            ->assertOk()->assertJsonPath('data.data.0.id', $this->product2->id)
            ->assertJsonPath('data.data.0.vendor.id', $this->vendor->id);
        $this->authGetJson('/api/orders/replacement-products?search=SHIRT-01')
            ->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_non_admin_cannot_replace_or_browse_replacement_products(): void
    {
        $order = $this->createTestOrder();
        foreach (['vendor', 'reseller', 'product manager'] as $role) {
            $user = User::factory()->create(['role' => $role, 'banned' => 0]);
            $token = ApiTokenService::create($user, ['basic'], 1, 'replacement-access-test')['plain'];
            $this->withToken($token)->putJson("/api/orders/update/{$order->id}", $this->replacementItems($order))->assertForbidden();
            $this->withToken($token)->getJson('/api/orders/replacement-products')->assertForbidden();
        }
    }

    public function test_invalid_replacement_never_changes_the_order_or_existing_item(): void
    {
        $order = $this->createTestOrder();
        $item = $order->items()->firstOrFail();
        $this->authPutJson("/api/orders/update/{$order->id}", $this->replacementItems($order, ['expected_product_id' => $this->product2->id]))->assertConflict();
        $this->authPutJson("/api/orders/update/{$order->id}", $this->replacementItems($order, ['attribute_id' => $this->attr1->id]))->assertUnprocessable();
        $this->authPutJson("/api/orders/update/{$order->id}", $this->replacementItems($order, ['reseller_price' => 799]))->assertUnprocessable();
        $this->authPutJson("/api/orders/update/{$order->id}", $this->replacementItems($order, ['id' => $item->id + 1]))->assertUnprocessable();
        $this->authPutJson("/api/orders/update/{$order->id}", $this->replacementItems($order) + ['total' => 5])->assertUnprocessable();
        $this->product2->update(['current_stock' => 0]);
        $this->authPutJson("/api/orders/update/{$order->id}", $this->replacementItems($order))->assertUnprocessable();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'total' => 900, 'vendor_id' => $this->vendor->id]);
        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'product_id' => $this->product1->id, 'sku' => 'SHIRT-01']);
        $this->assertSame(1, $order->items()->count());
        $this->assertDatabaseCount('order_item_change_logs', 0);
    }

    public function test_item_edit_audit_appends_each_admin_change_and_skips_no_op_saves(): void
    {
        $order = $this->createTestOrder();
        $item = $order->items()->sole();
        $order->update(['reseller_profit' => 191]);
        $item->update(['line_total_reseller_profit' => 191]);
        $url = "/api/orders/update/{$order->id}";
        $payload = ['items' => [[
            'id' => $item->id,
            'product_id' => $item->product_id,
            'qty' => 1,
            'reseller_price' => 800,
        ]]];
        $this->authPutJson($url, $payload)->assertOk();
        $this->assertDatabaseCount('order_item_change_logs', 0);

        $payload['items'][0]['qty'] = 2;
        $this->authPutJson($url, $payload)->assertOk();

        $otherAdmin = User::factory()->create(['role' => 'admin', 'banned' => 0]);
        $token = ApiTokenService::create($otherAdmin, ['basic', 'admin'], 1, 'second-admin')['plain'];
        $payload['items'][0]['reseller_price'] = 900;
        $payload['admin_id'] = $this->admin->id;
        $this->withToken($token)->putJson($url, $payload)->assertOk();

        $logs = OrderItemChangeLog::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertEquals($item->id, $logs[0]->order_item_id);
        $this->assertEquals($this->admin->id, $logs[0]->admin_id);
        $this->assertEquals($otherAdmin->id, $logs[1]->admin_id);
        $this->assertSame(['item_updated', 'item_updated'], $logs->pluck('action')->all());
        $this->assertSame(1, $logs[0]->previous_values['qty']);
        $this->assertSame(2, $logs[0]->new_values['qty']);
        $this->assertSame($logs[0]->new_values, $logs[1]->previous_values);
        $this->assertEquals(900, $logs[1]->new_values['reseller_price']);
        $this->assertNotNull($logs[1]->created_at);
    }

    public function test_replacement_requires_an_active_in_stock_variant_from_the_selected_product(): void
    {
        $order = $this->createTestOrder();
        $variant = ProductAttribute::create([
            'product_id' => $this->product2->id, 'attribute_id' => $this->attr1->attribute_id,
            'attribute_value_id' => $this->attr1->attribute_value_id, 'stock' => 0, 'is_active' => true,
        ]);
        $payload = $this->replacementItems($order, ['attribute_id' => $variant->id]);
        $this->authPutJson("/api/orders/update/{$order->id}", $payload)->assertUnprocessable();
        $variant->update(['stock' => 2, 'is_active' => false]);
        $this->authPutJson("/api/orders/update/{$order->id}", $payload)->assertUnprocessable();
        $variant->update(['is_active' => true]);
        $this->authPutJson("/api/orders/update/{$order->id}", $payload)->assertOk()
            ->assertJsonPath('data.items.0.attribute_id', $variant->id);
    }

    public function test_paid_settled_dispatched_and_partner_orders_cannot_be_replaced(): void
    {
        foreach ([['payment_status' => 'paid'], ['status' => 4], ['order_number' => 'ORD-P5-20261108-ABCDE']] as $state) {
            $order = $this->createTestOrder();
            $order->update($state);
            $this->authGetJson("/api/orders/details/{$order->id}")->assertJsonPath('status', 'success')
                ->assertJsonStructure(['data' => ['item_replacement_block_reason']]);
            $this->authPutJson("/api/orders/update/{$order->id}", $this->replacementItems($order))->assertUnprocessable();
        }

        $order = $this->createTestOrder();
        $order->settlements()->create([
            'user_type' => 'vendor', 'vendor_id' => $this->vendor->id,
            'settlement_type' => OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE,
            'settleable_amount' => 500, 'status' => OrderSettlement::STATUS_SETTLED,
        ]);
        $this->authPutJson("/api/orders/update/{$order->id}", $this->replacementItems($order))->assertUnprocessable();
    }

    public function test_replacement_is_blocked_for_multi_item_and_consignment_orders(): void
    {
        $multi = $this->createTestOrder();
        $secondItem = $multi->items()->create(['product_id' => $this->product2->id, 'qty' => 1]);
        $payload = $this->replacementItems($multi);
        $payload['items'][] = ['id' => $secondItem->id, 'qty' => 1, 'reseller_price' => 1000];
        $this->authPutJson("/api/orders/update/{$multi->id}", $payload)->assertUnprocessable();
        $assigned = $this->createTestOrder(true);
        $this->authPutJson("/api/orders/update/{$assigned->id}", $this->replacementItems($assigned))
            ->assertUnprocessable()->assertJsonPath('errors.locked', true);
    }

    public function test_courier_submission_with_old_product_is_rejected_before_creating_a_draft(): void
    {
        $order = $this->createTestOrder();
        $this->authPutJson("/api/orders/update/{$order->id}", $this->replacementItems($order))->assertOk();
        $payload = [
            'order_id' => $order->id, 'expected_product_id' => $this->product1->id,
            'store_id' => 123, 'merchant_order_id' => $order->order_number, 'delivery_type' => 1, 'product_type' => 1,
            'recipient_phone' => '01700000000', 'recipient_name' => 'Customer', 'recipient_address' => 'Dhaka',
            'city_id' => 1, 'zone_id' => 1, 'item_weight' => 500, 'item_quantity' => 1, 'collectable_amount' => 900,
        ];
        $this->withToken($this->adminToken)->postJson("/api/delivery-companies/carrybee/{$this->vendor->id}/orders", $payload)->assertConflict();
        $this->assertDatabaseMissing('carry_bee_order_create_forms', ['order_id' => $order->id]);
    }
}

