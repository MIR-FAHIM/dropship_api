<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductWinningProfile;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Partner Portal F6: winning products with ad hook / angle / comparison photos.
class WinningProductTest extends TestCase
{
    use RefreshDatabase;

    // 1x1 PNG (no GD needed).
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private User $admin;
    private User $marketer;
    private Product $cooler;
    private Product $draft;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->marketer = User::factory()->create(['user_type' => 'marketer', 'role' => 'marketer', 'banned' => 0]);
        $vendor = Vendor::create(['user_id' => User::factory()->create(['user_type' => 'vendor', 'banned' => 0])->id, 'shop_name' => 'S', 'is_active' => true]);
        $cat = Category::create(['name' => 'Smart Gadgets', 'slug' => 'gadgets']);
        $this->cooler = Product::create(['name' => 'Mini Air Cooler', 'slug' => 'cooler', 'sku' => 'AC1', 'vendor_id' => $vendor->id, 'category_id' => $cat->id,
            'unit_price' => 650, 'admin_price' => 683, 'max_resell_price' => 1290, 'approved' => 1]);
        $this->draft = Product::create(['name' => 'Draft', 'slug' => 'draft', 'sku' => 'D1', 'vendor_id' => $vendor->id, 'category_id' => $cat->id,
            'unit_price' => 100, 'admin_price' => 105, 'approved' => 0]);
    }

    private function h(User $u): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($u, ['basic'])['plain'], 'Accept' => 'application/json'];
    }

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private function save(Product $p, array $over = [])
    {
        return $this->post("/api/admin/products/{$p->id}/winning", $over + [
            'hook' => 'Beat load-shedding heat with instant mist cooling!',
            'ad_angle' => 'Hot room air vs instant cold mist in the first 3 seconds.',
            'local_caption' => 'Local copy: weak motor & leaks',
            'original_caption' => 'Verified original: dual mist, silent',
            'local_image' => $this->png('low.png'),
            'original_image' => $this->png('high.png'),
        ], $this->h($this->admin));
    }

    public function test_admin_marks_a_product_winning_with_photos_and_marketer_sees_the_card(): void
    {
        $profile = $this->save($this->cooler)->assertOk()->json('data');
        Storage::disk('public')->assertExists($profile['local_image']);
        Storage::disk('public')->assertExists($profile['original_image']);

        $cards = $this->getJson('/api/marketer/winning-products', $this->h($this->marketer))->assertOk()->json('data.data');
        $this->assertCount(1, $cards);
        $c = $cards[0];
        $this->assertSame('Mini Air Cooler', $c['name']);
        $this->assertSame('Smart Gadgets', $c['category']);
        $this->assertEquals(683, $c['rb_price']);
        $this->assertEquals(1290, $c['suggested_price']);
        $this->assertEquals(607, $c['client_profit']);
        $this->assertTrue($c['has_comparison']);
        $this->assertStringContainsString('mist', $c['hook']);
        $this->assertStringNotContainsString('650', json_encode($c), 'supplier price is never sent');
        $this->assertArrayNotHasKey('unit_price', $c);
    }

    public function test_hook_and_angle_are_required_and_photos_can_be_replaced_or_removed(): void
    {
        $this->post("/api/admin/products/{$this->cooler->id}/winning", ['ad_angle' => 'x'], $this->h($this->admin))->assertUnprocessable();
        $first = $this->save($this->cooler)->json('data');

        $second = $this->save($this->cooler, ['local_image' => $this->png('low2.png'), 'remove_original_image' => 1, 'original_image' => null])->assertOk()->json('data');
        Storage::disk('public')->assertMissing($first['local_image']);
        Storage::disk('public')->assertExists($second['local_image']);
        Storage::disk('public')->assertMissing($first['original_image']);
        $this->assertNull($second['original_image']);
        $this->assertSame(1, ProductWinningProfile::count(), 'one profile per product');
    }

    public function test_unmarking_hides_it_but_keeps_the_content_and_unapproved_products_never_show(): void
    {
        $this->save($this->cooler);
        $this->save($this->draft);
        $this->assertCount(1, $this->getJson('/api/marketer/winning-products', $this->h($this->marketer))->json('data.data'), 'unapproved product hidden');

        $this->deleteJson("/api/admin/products/{$this->cooler->id}/winning", [], $this->h($this->admin))->assertOk();
        $this->assertCount(0, $this->getJson('/api/marketer/winning-products', $this->h($this->marketer))->json('data.data'));
        $this->assertNotNull(ProductWinningProfile::where('product_id', $this->cooler->id)->value('hook'), 'content kept');
        $this->getJson("/api/admin/products/{$this->cooler->id}/winning", $this->h($this->admin))->assertOk()->assertJsonPath('data.is_winning', false);
    }

    public function test_stars_from_the_calculator_show_on_cards(): void
    {
        $this->save($this->cooler);
        $h = $this->h($this->marketer);
        $this->postJson("/api/marketer/pitch/products/{$this->cooler->id}/star", [], $h)->assertOk();
        $this->assertTrue($this->getJson('/api/marketer/winning-products', $h)->json('data.data.0.starred'));
        $this->assertCount(1, $this->getJson('/api/marketer/winning-products?starred=1', $h)->json('data.data'));
    }

    public function test_access_rules(): void
    {
        $this->post("/api/admin/products/{$this->cooler->id}/winning", ['hook' => 'h', 'ad_angle' => 'a'], $this->h($this->marketer))->assertForbidden();
        $d = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->getJson('/api/marketer/winning-products', $this->h($d))->assertForbidden();
        $this->getJson('/api/admin/winning-products', $this->h($d))->assertForbidden();
    }
}
