<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Partner Portal F5: products, stars and dollar rate for the Pitch Calculator.
class MarketerPitchTest extends TestCase
{
    use RefreshDatabase;

    private User $marketer;
    private Product $fan;
    private Product $cooler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marketer = User::factory()->create(['user_type' => 'marketer', 'role' => 'marketer', 'banned' => 0]);
        $vendor = Vendor::create(['user_id' => User::factory()->create(['user_type' => 'vendor', 'banned' => 0])->id, 'shop_name' => 'S', 'is_active' => true]);
        $cat = Category::create(['name' => 'Smart Gadgets', 'slug' => 'gadgets']);
        $make = fn (string $name, array $extra = []) => Product::create($extra + ['name' => $name, 'slug' => str()->slug($name) . uniqid(), 'sku' => strtoupper(substr($name, 0, 3)),
            'vendor_id' => $vendor->id, 'category_id' => $cat->id, 'unit_price' => 500, 'admin_price' => 525, 'max_resell_price' => 990, 'approved' => 1]);
        $this->fan = $make('Mini Fan');
        $this->cooler = $make('Air Cooler', ['admin_price' => 683]);
        $make('Hidden Draft', ['approved' => 0]);
    }

    private function h(User $u): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($u, ['basic'])['plain']];
    }

    public function test_products_show_rb_price_but_never_the_supplier_price(): void
    {
        $rows = $this->getJson('/api/marketer/pitch/products', $this->h($this->marketer))->assertOk()->json('data.data');

        $this->assertCount(2, $rows, 'unapproved products are hidden');
        $fan = collect($rows)->firstWhere('id', $this->fan->id);
        $this->assertEquals(525, $fan['rb_price']);
        $this->assertEquals(990, $fan['suggested_price']);
        $this->assertSame('Smart Gadgets', $fan['category']);
        $this->assertArrayNotHasKey('unit_price', $fan);
        $this->assertStringNotContainsString('unit_price', json_encode($rows));

        $this->assertCount(1, $this->getJson('/api/marketer/pitch/products?search=cooler', $this->h($this->marketer))->json('data.data'));
        $this->assertCount(2, $this->getJson('/api/marketer/pitch/products?search=gadget', $this->h($this->marketer))->json('data.data'));
    }

    public function test_stars_are_per_marketer_and_listed_first(): void
    {
        $h = $this->h($this->marketer);
        $this->postJson("/api/marketer/pitch/products/{$this->fan->id}/star", [], $h)->assertOk();
        $this->postJson("/api/marketer/pitch/products/{$this->fan->id}/star", [], $h)->assertOk(); // twice is fine

        $rows = $this->getJson('/api/marketer/pitch/products', $h)->json('data.data');
        $this->assertSame($this->fan->id, $rows[0]['id']);
        $this->assertTrue($rows[0]['starred']);
        $this->assertCount(1, $this->getJson('/api/marketer/pitch/products?starred=1', $h)->json('data.data'));

        $other = User::factory()->create(['user_type' => 'marketer', 'role' => 'marketer', 'banned' => 0]);
        $this->assertCount(0, $this->getJson('/api/marketer/pitch/products?starred=1', $this->h($other))->json('data.data'));

        $this->deleteJson("/api/marketer/pitch/products/{$this->fan->id}/star", [], $h)->assertOk();
        $this->assertCount(0, $this->getJson('/api/marketer/pitch/products?starred=1', $h)->json('data.data'));
        $this->postJson('/api/marketer/pitch/products/999999/star', [], $h)->assertNotFound();
    }

    public function test_dollar_rate_is_saved_on_the_profile(): void
    {
        $h = $this->h($this->marketer);
        $this->getJson('/api/marketer/pitch/settings', $h)->assertOk()->assertJsonPath('data.dollar_rate', 125)->assertJsonPath('data.dollar_rate_saved', false);
        $this->putJson('/api/marketer/pitch/settings', ['dollar_rate' => 10], $h)->assertUnprocessable();
        $this->putJson('/api/marketer/pitch/settings', ['dollar_rate' => 128.5], $h)->assertOk();
        $this->getJson('/api/marketer/pitch/settings', $h)->assertJsonPath('data.dollar_rate', 128.5)->assertJsonPath('data.dollar_rate_saved', true);
    }

    public function test_only_marketers(): void
    {
        $d = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->getJson('/api/marketer/pitch/products', $this->h($d))->assertForbidden();
    }
}
