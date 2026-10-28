<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAllOrdersVendorFilterTest extends TestCase
{
    use RefreshDatabase;

    private function setupLocations(): array
    {
        $dhakaDiv = Division::create(['name' => 'Dhaka', 'bn_name' => 'ঢাকা', 'url' => 'dhaka']);
        $dhakaDist = District::create(['division_id' => $dhakaDiv->id, 'name' => 'Dhaka', 'bn_name' => 'ঢাকা', 'url' => 'dhaka']);
        return [$dhakaDiv, $dhakaDist];
    }

    public function test_all_orders_can_be_filtered_by_vendor_id(): void
    {
        [, $district] = $this->setupLocations();
        $statusPending = OrderStatus::create(['name' => 'Pending', 'type' => 'order', 'is_active' => true]);

        $admin = User::create([
            'name'      => 'Admin User',
            'email'     => 'admin_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'user_type' => 'admin',
            'banned'    => 0,
        ]);
        $tokenData = ApiTokenService::create($admin, ['basic', 'admin'], 30, 'admin-token');

        $vendorUser1 = User::create([
            'name'      => 'Vendor One',
            'email'     => 'vendor1_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'vendor',
            'user_type' => 'vendor',
            'banned'    => 0,
        ]);
        $vendor1 = Vendor::create([
            'user_id'   => $vendorUser1->id,
            'shop_name' => 'Alpha Shop',
            'district'  => $district->id,
        ]);

        $vendorUser2 = User::create([
            'name'      => 'Vendor Two',
            'email'     => 'vendor2_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'vendor',
            'user_type' => 'vendor',
            'banned'    => 0,
        ]);
        $vendor2 = Vendor::create([
            'user_id'   => $vendorUser2->id,
            'shop_name' => 'Beta Shop',
            'district'  => $district->id,
        ]);

        // Order 1: directly associated with Vendor 1
        $order1 = Order::create([
            'user_id'        => $admin->id,
            'order_number'   => 'ORD-V1-001',
            'status'         => $statusPending->id,
            'payment_status' => 'unpaid',
            'customer_name'  => 'Customer Alpha',
            'total'          => 500,
            'vendor_id'      => $vendor1->id,
        ]);
        OrderItem::create([
            'order_id'       => $order1->id,
            'product_name'   => 'Product A',
            'shop_id'        => $vendor1->id,
            'qty'            => 1,
            'unit_price'     => 500,
            'line_total'     => 500,
        ]);

        // Order 2: directly associated with Vendor 2
        $order2 = Order::create([
            'user_id'        => $admin->id,
            'order_number'   => 'ORD-V2-002',
            'status'         => $statusPending->id,
            'payment_status' => 'unpaid',
            'customer_name'  => 'Customer Beta',
            'total'          => 700,
            'vendor_id'      => $vendor2->id,
        ]);
        OrderItem::create([
            'order_id'       => $order2->id,
            'product_name'   => 'Product B',
            'shop_id'        => $vendor2->id,
            'qty'            => 1,
            'unit_price'     => 700,
            'line_total'     => 700,
        ]);

        // Order 3: vendor_id is null on order, but item shop_id is Vendor 1
        $order3 = Order::create([
            'user_id'        => $admin->id,
            'order_number'   => 'ORD-V1-003',
            'status'         => $statusPending->id,
            'payment_status' => 'unpaid',
            'customer_name'  => 'Customer Gamma',
            'total'          => 300,
            'vendor_id'      => null,
        ]);
        OrderItem::create([
            'order_id'       => $order3->id,
            'product_name'   => 'Product C',
            'shop_id'        => $vendor1->id,
            'qty'            => 1,
            'unit_price'     => 300,
            'line_total'     => 300,
        ]);

        $headers = ['Authorization' => 'Bearer ' . $tokenData['plain']];

        // 1. All orders without filter
        $resAll = $this->withHeaders($headers)->getJson('/api/orders/all/orders');
        $resAll->assertStatus(200);
        $this->assertCount(3, $resAll->json('data.data'));

        // 2. Filter by vendor_id = Vendor 1 (should return Order 1 and Order 3)
        $resVendor1 = $this->withHeaders($headers)->getJson("/api/orders/all/orders?vendor_id={$vendor1->id}");
        $resVendor1->assertStatus(200);
        $v1OrderNumbers = collect($resVendor1->json('data.data'))->pluck('order_number')->all();
        $this->assertCount(2, $v1OrderNumbers);
        $this->assertContains('ORD-V1-001', $v1OrderNumbers);
        $this->assertContains('ORD-V1-003', $v1OrderNumbers);
        $this->assertNotContains('ORD-V2-002', $v1OrderNumbers);

        // 3. Filter by vendor_id = Vendor 2 (should return only Order 2)
        $resVendor2 = $this->withHeaders($headers)->getJson("/api/orders/all/orders?vendor_id={$vendor2->id}");
        $resVendor2->assertStatus(200);
        $v2OrderNumbers = collect($resVendor2->json('data.data'))->pluck('order_number')->all();
        $this->assertCount(1, $v2OrderNumbers);
        $this->assertContains('ORD-V2-002', $v2OrderNumbers);

        // 4. Filter by non-existent vendor_id = 99999
        $resEmpty = $this->withHeaders($headers)->getJson('/api/orders/all/orders?vendor_id=99999');
        $resEmpty->assertStatus(200);
        $this->assertCount(0, $resEmpty->json('data.data'));

        // 5. Test fallback parameter 'vendor'
        $resFallback = $this->withHeaders($headers)->getJson("/api/orders/all/orders?vendor={$vendor2->id}");
        $resFallback->assertStatus(200);
        $this->assertCount(1, $resFallback->json('data.data'));
    }
}
