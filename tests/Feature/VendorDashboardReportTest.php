<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorDashboardReportTest extends TestCase
{
    use RefreshDatabase;

    private function createProduct(Category $category, Vendor $vendor, string $name, float $price): Product
    {
        return Product::create([
            'category_id'  => $category->id,
            'vendor_id'    => $vendor->id,
            'name'         => $name,
            'slug'         => strtolower(str_replace(' ', '-', $name)),
            'unit_price'   => $price,
            'admin_price'  => $price + 50,
            'published'    => 1,
            'approved'     => 1,
        ]);
    }

    private function createOrderWithItem(User $customer, OrderStatus $status, Product $product, float $lineTotal, Carbon $date): Order
    {
        $order = new Order([
            'user_id'        => $customer->id,
            'order_number'   => 'ORD-' . uniqid(),
            'status'         => $status->id,
            'total'          => $lineTotal,
        ]);
        $order->timestamps = false;
        $order->created_at = $date;
        $order->updated_at = $date;
        $order->save();

        $item = new OrderItem([
            'order_id'       => $order->id,
            'product_id'     => $product->id,
            'shop_id'        => $product->vendor_id,
            'product_name'   => $product->name,
            'unit_price'     => $lineTotal,
            'qty'            => 1,
            'line_total'     => $lineTotal,
        ]);
        $item->timestamps = false;
        $item->created_at = $date;
        $item->updated_at = $date;
        $item->save();

        return $order;
    }

    public function test_vendor_dashboard_report_sales_only_includes_completed_orders_and_separates_pending(): void
    {
        $now = Carbon::now();

        // 1. Create Vendor & Category
        $vendorUser = User::create([
            'name'      => 'Bongobox User',
            'email'     => 'bongobox@example.com',
            'password'  => bcrypt('password123'),
            'user_type' => 'vendor',
            'banned'    => 0,
        ]);

        $vendor = Vendor::create([
            'user_id'   => $vendorUser->id,
            'shop_name' => 'Bongobox',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'Home Appliances',
            'slug' => 'home-appliances',
        ]);

        $product1 = $this->createProduct($category, $vendor, 'Grinding Machine', 1030);
        $product2 = $this->createProduct($category, $vendor, 'Electric Kettle', 560);

        // 2. Create Order Statuses
        $statusDelivered = OrderStatus::create(['name' => 'Delivered', 'type' => 'delivered', 'is_active' => true]);
        $statusCompleted = OrderStatus::create(['name' => 'Completed', 'type' => 'completed', 'is_active' => true]);
        $statusCancelled = OrderStatus::create(['name' => 'Cancelled', 'type' => 'cancelled', 'is_active' => true]);
        $statusReturned  = OrderStatus::create(['name' => 'Returned', 'type' => 'returned', 'is_active' => true]);
        $statusPending   = OrderStatus::create(['name' => 'Pending', 'type' => 'pending', 'is_active' => true]);
        $statusReady     = OrderStatus::create(['name' => 'Vendor Is Ready', 'type' => 'processing', 'is_active' => true]);

        // Customer
        $customer = User::create([
            'name'      => 'Customer One',
            'email'     => 'customer@example.com',
            'password'  => bcrypt('password123'),
            'user_type' => 'customer',
        ]);

        // 3. Create Orders:
        // Order 1 (Delivered today): 1020 Tk
        $this->createOrderWithItem($customer, $statusDelivered, $product1, 1020, $now);

        // Order 2 (Completed yesterday): 500 Tk
        $this->createOrderWithItem($customer, $statusCompleted, $product2, 500, $now->copy()->subDay());

        // Order 3 (Cancelled with massive test amount): 50,000,000 Tk -> MUST BE ELIMINATED
        $this->createOrderWithItem($customer, $statusCancelled, $product1, 50000000, $now->copy()->subDays(2));

        // Order 4 (Returned): 1,000,000 Tk -> MUST BE ELIMINATED
        $this->createOrderWithItem($customer, $statusReturned, $product2, 1000000, $now->copy()->subDays(3));

        // Order 5 (Pending): 2000 Tk -> Must be in pending_sales
        $this->createOrderWithItem($customer, $statusPending, $product1, 2000, $now);

        // Order 6 (Vendor Is Ready): 1500 Tk -> Must be in pending_sales
        $this->createOrderWithItem($customer, $statusReady, $product2, 1500, $now->copy()->subDay());

        // 4. Call Dashboard Report API
        $response = $this->getJson("/api/vendors/dashboard/report/{$vendor->id}");

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Vendor dashboard report fetched',
            ]);

        $sales = $response->json('data.sales');
        $orders = $response->json('data.orders');

        // Total completed sales must be 1020 + 500 = 1520 (Cancelled 50,000,000 + 1,000,000 completely excluded!)
        $this->assertEquals(1520, $sales['total']);
        $this->assertEquals(1520, $sales['completed_sales']);

        // Pending sales must be 2000 + 1500 = 3500
        $this->assertEquals(3500, $sales['pending_sales']);
        $this->assertEquals(3500, $sales['pending']);

        // Today completed sales should be 1020
        $this->assertEquals(1020, $sales['today']);
        // Yesterday completed sales should be 500
        $this->assertEquals(500, $sales['yesterday']);

        // Total orders must still show all 6 orders
        $this->assertEquals(6, $orders['total']);

        // Orders by status must have counts
        $byStatus = collect($orders['by_status']);
        $delivered = $byStatus->firstWhere('status', 'Delivered');
        $this->assertNotNull($delivered);
        $this->assertEquals(1, $delivered['total']);
        $this->assertEquals(1, $delivered['count']);

        $cancelled = $byStatus->firstWhere('status', 'Cancelled');
        $this->assertNotNull($cancelled);
        $this->assertEquals(1, $cancelled['total']);
    }
}
