<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Admin product page: changing a product's vendor must be saved (it used to be dropped).
class ProductVendorChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerUser;
    private Vendor $owner;
    private Vendor $newVendor;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerUser = User::factory()->create(['user_type' => 'vendor', 'banned' => 0]);
        $this->owner = Vendor::create(['user_id' => $this->ownerUser->id, 'shop_name' => 'Old Shop']);
        $this->newVendor = Vendor::create(['user_id' => User::factory()->create(['user_type' => 'vendor', 'banned' => 0])->id, 'shop_name' => 'New Shop']);
        $category = Category::create(['name' => 'Kitchen', 'slug' => 'kitchen']);
        $this->product = Product::create([
            'name' => 'Cooker', 'slug' => 'cooker', 'sku' => 'C-1', 'vendor_id' => $this->owner->id,
            'category_id' => $category->id, 'unit_price' => 1050, 'admin_price' => 1101, 'current_stock' => 5,
        ]);
    }

    private function update(User $user, array $body)
    {
        $headers = ['Authorization' => 'Bearer ' . ApiTokenService::create($user, ['basic'])['plain']];

        return $this->postJson("/api/products/update/{$this->product->id}", $body, $headers);
    }

    public function test_admin_can_move_a_product_to_another_vendor(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);

        // Same shape the admin page sends: vendor_id as a string, plus the other fields.
        $this->update($admin, ['vendor_id' => (string) $this->newVendor->id, 'name' => 'Cooker', 'brand_id' => '', 'weight' => 2])
            ->assertOk()
            ->assertJsonPath('data.vendor_id', $this->newVendor->id);

        $this->assertSame($this->newVendor->id, $this->product->fresh()->vendor_id);
    }

    public function test_vendor_cannot_move_their_product_to_another_vendor(): void
    {
        $this->update($this->ownerUser, ['vendor_id' => $this->newVendor->id])->assertForbidden();
        $this->assertSame($this->owner->id, $this->product->fresh()->vendor_id);
    }

    public function test_vendor_can_still_save_their_product_with_the_same_vendor_id(): void
    {
        $this->update($this->ownerUser, ['vendor_id' => $this->owner->id, 'name' => 'Cooker 2'])->assertOk();
        $this->assertSame('Cooker 2', $this->product->fresh()->name);
    }

    public function test_unknown_vendor_is_refused(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);

        $this->update($admin, ['vendor_id' => 999999])->assertUnprocessable();
        $this->assertSame($this->owner->id, $this->product->fresh()->vendor_id);
    }
}
