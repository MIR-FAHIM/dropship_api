<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductStockUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['user_type' => 'vendor', 'banned' => 0]);
        $vendor = Vendor::create(['user_id' => $this->owner->id, 'shop_name' => 'Stock Shop']);
        $category = Category::create(['name' => 'Stock Category', 'slug' => 'stock-category']);
        $this->product = Product::create([
            'name' => 'Stock Product',
            'slug' => 'stock-product',
            'sku' => 'STOCK-1',
            'vendor_id' => $vendor->id,
            'category_id' => $category->id,
            'unit_price' => 100,
            'admin_price' => 120,
            'current_stock' => 5,
        ]);
    }

    public function test_vendor_can_update_own_stock_to_zero_without_changing_price_or_sku(): void
    {
        $this->updateStock($this->owner, 0)->assertOk()->assertJsonPath('data.current_stock', 0);

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'current_stock' => 0,
            'sku' => 'STOCK-1',
            'unit_price' => 100,
            'admin_price' => 120,
        ]);
    }

    public function test_vendor_cannot_update_another_vendors_stock(): void
    {
        $otherVendor = User::factory()->create(['user_type' => 'vendor', 'banned' => 0]);
        Vendor::create(['user_id' => $otherVendor->id, 'shop_name' => 'Other Shop']);

        $this->updateStock($otherVendor, 10)->assertForbidden();
        $this->assertEquals(5, $this->product->fresh()->current_stock);
    }

    public function test_stock_must_be_a_non_negative_whole_number_within_the_column_limit(): void
    {
        foreach ([-1, 1.5, null, 2147483648] as $invalidStock) {
            $this->updateStock($this->owner, $invalidStock)
                ->assertUnprocessable()
                ->assertJsonStructure(['errors' => ['current_stock']]);
        }

        $this->assertEquals(5, $this->product->fresh()->current_stock);
    }

    public function test_admin_can_still_update_vendor_stock(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin', 'banned' => 0]);

        $this->updateStock($admin, 10)->assertOk();
        $this->assertEquals(10, $this->product->fresh()->current_stock);
    }

    private function updateStock(User $user, mixed $stock)
    {
        $token = ApiTokenService::create($user, ['basic'], 1, 'test')['plain'];

        return $this->withToken($token)->postJson('/api/products/update/'.$this->product->id, [
            'current_stock' => $stock,
        ]);
    }
}
