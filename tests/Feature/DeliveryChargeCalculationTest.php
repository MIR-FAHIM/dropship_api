<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\District;
use App\Models\Division;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryChargeCalculationTest extends TestCase
{
    use RefreshDatabase;

    private function setupLocations(): array
    {
        $dhakaDiv = Division::create(['name' => 'Dhaka', 'bn_name' => 'ঢাকা', 'url' => 'www.dhakadiv.gov.bd']);
        $ctgDiv   = Division::create(['name' => 'Chattogram', 'bn_name' => 'চট্টগ্রাম', 'url' => 'www.chittagongdiv.gov.bd']);

        $dhakaDist = District::create(['division_id' => $dhakaDiv->id, 'name' => 'Dhaka', 'bn_name' => 'ঢাকা', 'url' => 'www.dhaka.gov.bd']);
        $ctgDist   = District::create(['division_id' => $ctgDiv->id, 'name' => 'Chattogram', 'bn_name' => 'চট্টগ্রাম', 'url' => 'www.chittagong.gov.bd']);

        return [$dhakaDist, $ctgDist];
    }

    public function test_calculate_delivery_charge_same_district(): void
    {
        [$dhakaDist, $ctgDist] = $this->setupLocations();

        $response = $this->getJson("/api/deliveries/delivery-charge?customer_district_id={$dhakaDist->id}&vendor_district_id={$dhakaDist->id}");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'charge' => 70,
                    'zone' => 'Inside District',
                    'same_district' => true,
                ],
            ]);
    }

    public function test_calculate_delivery_charge_outside_district(): void
    {
        [$dhakaDist, $ctgDist] = $this->setupLocations();

        $response = $this->getJson("/api/deliveries/delivery-charge?customer_district_id={$ctgDist->id}&vendor_district_id={$dhakaDist->id}");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'charge' => 130,
                    'zone' => 'Outside District',
                    'same_district' => false,
                ],
            ]);
    }

    public function test_calculate_delivery_charge_defaults_vendor_to_dhaka_if_omitted(): void
    {
        [$dhakaDist, $ctgDist] = $this->setupLocations();

        // Calling without vendor_district_id
        $responseInside = $this->getJson("/api/deliveries/delivery-charge?customer_district_id={$dhakaDist->id}");
        $responseInside->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'charge' => 70,
                    'zone' => 'Inside District',
                ],
            ]);

        $responseOutside = $this->getJson("/api/deliveries/delivery-charge?customer_district_id={$ctgDist->id}");
        $responseOutside->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'charge' => 130,
                    'zone' => 'Outside District',
                ],
            ]);
    }

    public function test_checkout_requires_delivery_charge_and_sets_dynamic_advance_amount(): void
    {
        OrderStatus::create(['name' => 'Pending', 'type' => 'order', 'is_active' => true]);

        [$dhakaDist, $ctgDist] = $this->setupLocations();

        $user = User::create([
            'name'      => 'Dropshipper One',
            'email'     => 'dropshipper_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'customer',
            'user_type' => 'dropshipper',
            'banned'    => 0,
        ]);
        $tokenData = ApiTokenService::create($user, ['basic'], 30, 'token');

        $vendorUser = User::create([
            'name'      => 'Vendor One',
            'email'     => 'vendor_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'vendor',
            'user_type' => 'vendor',
            'banned'    => 0,
        ]);
        $vendor = Vendor::create([
            'user_id'   => $vendorUser->id,
            'shop_name' => 'Test Vendor Shop',
            'district'  => $dhakaDist->id,
        ]);

        $cat = Category::create(['name' => 'Gadgets', 'slug' => 'gadgets', 'order_level' => 1, 'commision_rate' => 0]);
        $prod = Product::create([
            'name'           => 'Wireless Earbuds',
            'slug'           => 'wireless-earbuds-' . uniqid(),
            'added_by'       => 'vendor',
            'vendor_id'      => $vendor->id,
            'category_id'    => $cat->id,
            'unit_price'     => 400,
            'admin_price'    => 450,
            'purchase_price' => 350,
            'published'      => 1,
            'approved'       => 1,
        ]);

        $cart = Cart::create([
            'user_id'     => $user->id,
            'status'      => 'active',
            'total_items' => 1,
            'subtotal'    => 500,
        ]);
        CartItem::create([
            'cart_id'        => $cart->id,
            'product_id'     => $prod->id,
            'shop_id'        => $vendor->id,
            'qty'            => 1,
            'unit_price'     => 400,
            'admin_price'    => 450,
            'reseller_price' => 500,
            'line_total'     => 500,
        ]);

        // Missing delivery_charge should fail validation
        $failResponse = $this->withHeader('Authorization', 'Bearer ' . $tokenData['plain'])
            ->postJson('/api/orders/checkout', [
                'user_id'          => $user->id,
                'customer_name'    => 'Customer Test',
                'customer_phone'   => '01700000000',
                'shipping_address' => 'Mirpur, Dhaka',
            ]);
        $failResponse->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_charge']);

        // Successful checkout with dynamic 70 BDT delivery charge
        $successResponse = $this->withHeader('Authorization', 'Bearer ' . $tokenData['plain'])
            ->postJson('/api/orders/checkout', [
                'user_id'                => $user->id,
                'customer_name'          => 'Customer Test',
                'customer_phone'         => '01700000000',
                'shipping_address'       => 'Mirpur, Dhaka',
                'zone'                   => 'Inside District',
                'district'               => 'Dhaka',
                'delivery_charge'        => 70,
                'payment_method'         => 'cod',
                'advance_payment_method' => 'pay_later',
                'advance_amount'         => 70,
            ]);

        $successResponse->assertStatus(201);

        $order = Order::latest('id')->first();
        $this->assertNotNull($order);
        $this->assertEquals(70.00, (float) $order->shipping_fee);
        $this->assertEquals(570.00, (float) $order->total);
        $this->assertEquals(70.00, (float) $order->advance_amount);
        $this->assertEquals('pending', $order->advance_status);
        $this->assertEquals('pay_later', $order->advance_payment_method);
    }
}
