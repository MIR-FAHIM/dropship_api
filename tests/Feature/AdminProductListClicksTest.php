<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductClicks;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Admin products list shows how many times each product was clicked.
class AdminProductListClicksTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_list_includes_clicks_count(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $vendorUser = User::factory()->create(['user_type' => 'vendor', 'role' => 'vendor', 'banned' => 0]);
        $vendor = Vendor::create(['user_id' => $vendorUser->id, 'shop_name' => 'Shop', 'is_active' => true]);
        $category = Category::create(['name' => 'Kitchen', 'slug' => 'kitchen-' . uniqid(), 'is_active' => true]);
        $make = fn (string $sku) => Product::create([
            'name' => "Product {$sku}", 'slug' => 'p-' . uniqid(), 'sku' => $sku, 'unit_price' => 500, 'admin_price' => 600, 'price' => 800,
            'current_stock' => 10, 'category_id' => $category->id, 'vendor_id' => $vendor->id, 'status' => 1,
        ]);
        $popular = $make('POP');
        $quiet = $make('QUIET');
        foreach (range(1, 3) as $i) {
            ProductClicks::create(['product_id' => $popular->id, 'page' => 'details', 'clicked_by' => $admin->id, 'is_guest' => 0, 'ip_address' => '127.0.0.1']);
        }

        $headers = ['Authorization' => 'Bearer ' . ApiTokenService::create($admin, ['basic', 'admin'])['plain']];
        $rows = collect($this->getJson('/api/products/list?per_page=50', $headers)->assertOk()->json('data.data'))->keyBy('sku');

        $this->assertSame(3, $rows['POP']['clicks_count']);
        $this->assertSame(0, $rows['QUIET']['clicks_count']);
    }
}
