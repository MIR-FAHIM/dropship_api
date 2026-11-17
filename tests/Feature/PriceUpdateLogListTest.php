<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PriceUpdateLog;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// /admin-panel/products/price-update-logs: newest first, paginated, filters.
class PriceUpdateLogListTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;
    private Product $shirt;
    private Product $mug;
    private Vendor $vendorA;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->headers = ['Authorization' => 'Bearer ' . ApiTokenService::create($admin, ['basic', 'admin'])['plain']];

        $this->vendorA = Vendor::create(['user_id' => User::factory()->create(['user_type' => 'vendor'])->id, 'shop_name' => 'Alpha Shop']);
        $vendorB = Vendor::create(['user_id' => User::factory()->create(['user_type' => 'vendor'])->id, 'shop_name' => 'Beta Shop']);

        $category = Category::create(['name' => 'Misc', 'slug' => 'misc-' . uniqid(), 'is_active' => true]);
        $this->shirt = Product::create(['name' => 'Cotton Shirt', 'slug' => 'shirt-' . uniqid(), 'sku' => 'SHIRT-1', 'category_id' => $category->id,
            'vendor_id' => $this->vendorA->id, 'unit_price' => 500, 'admin_price' => 600, 'price' => 800, 'status' => 1]);
        $this->mug = Product::create(['name' => 'Coffee Mug', 'slug' => 'mug-' . uniqid(), 'sku' => 'MUG-9', 'category_id' => $category->id,
            'vendor_id' => $vendorB->id, 'unit_price' => 200, 'admin_price' => 250, 'price' => 350, 'status' => 1]);

        // 4 Nov 20:00 UTC = 5 Nov Dhaka; 6 Nov; 7 Nov (newest)
        $this->log($this->shirt, 450, 500, '2026-11-04 20:00:00');
        $this->log($this->mug, 180, 200, '2026-11-06 06:00:00');
        $this->log($this->shirt, 500, 520, '2026-11-07 06:00:00');
    }

    private function log(Product $product, float $before, float $new, string $atUtc): void
    {
        $log = PriceUpdateLog::create(['product_id' => $product->id, 'before_price' => $before, 'new_price' => $new, 'status' => 'updated',
            'updated_by' => User::where('user_type', 'admin')->value('id')]);
        $log->forceFill(['created_at' => $atUtc])->save();
    }

    private function fetch(array $query = []): array
    {
        return $this->getJson('/api/price-update-logs/list?' . http_build_query($query), $this->headers)->assertOk()->json('data');
    }

    private function pairs(array $query = []): array
    {
        return collect($this->fetch($query)['data'])->map(fn ($l) => $l['product']['sku'] . ':' . (float) $l['new_price'])->all();
    }

    public function test_newest_first_with_product_and_supplier(): void
    {
        $page = $this->fetch();
        $this->assertSame(['SHIRT-1:520', 'MUG-9:200', 'SHIRT-1:500'], $this->pairs());
        $this->assertSame(3, $page['total']);
        $this->assertSame('Alpha Shop', $page['data'][0]['product']['vendor']['shop_name']);
    }

    public function test_paginates(): void
    {
        $page = $this->fetch(['per_page' => 2, 'page' => 2]);
        $this->assertSame(2, $page['last_page']);
        $this->assertSame(['SHIRT-1:500'], collect($page['data'])->map(fn ($l) => $l['product']['sku'] . ':' . (float) $l['new_price'])->all());
    }

    public function test_search_by_name_sku_or_id(): void
    {
        $this->assertSame(['MUG-9:200'], $this->pairs(['search' => 'coffee']));
        $this->assertSame(['MUG-9:200'], $this->pairs(['search' => 'MUG-9']));
        $this->assertSame(['SHIRT-1:520', 'SHIRT-1:500'], $this->pairs(['search' => (string) $this->shirt->id]));
    }

    public function test_supplier_and_date_filters(): void
    {
        $this->assertSame(['SHIRT-1:520', 'SHIRT-1:500'], $this->pairs(['vendor_id' => $this->vendorA->id]));
        // 5 Nov in Dhaka includes the 4 Nov 20:00 UTC change.
        $this->assertSame(['SHIRT-1:500'], $this->pairs(['date_from' => '2026-11-05', 'date_to' => '2026-11-05']));
        $this->assertSame(['SHIRT-1:520', 'MUG-9:200'], $this->pairs(['date_from' => '2026-11-06']));
        $this->assertSame(['SHIRT-1:520'], $this->pairs(['vendor_id' => $this->vendorA->id, 'date_from' => '2026-11-06']));
    }

    public function test_admin_only(): void
    {
        $dropshipper = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->getJson('/api/price-update-logs/list', ['Authorization' => 'Bearer ' . ApiTokenService::create($dropshipper, ['basic'])['plain']])
            ->assertStatus(403);
    }
}
