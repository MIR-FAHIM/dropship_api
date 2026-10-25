<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\DeliveryAssignedInfo;
use App\Models\DeliveryInformation;
use App\Models\Order;
use App\Models\OrderItem;
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
        // Profit: 2 * (850 - 600) = 500
        $this->assertEquals(500.00, (float) $order->reseller_profit);

        $updatedItem = $order->items()->first();
        $this->assertEquals(2, $updatedItem->qty);
        $this->assertEquals(850.00, (float) $updatedItem->reseller_price);
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
}

