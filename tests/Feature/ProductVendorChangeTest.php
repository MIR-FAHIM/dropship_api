<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Admin product page → Change vendor: product, auto SKU and open carts move; old orders stay; logged.
class ProductVendorChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $ownerUser;
    private Vendor $owner;
    private Vendor $newVendor;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['user_type' => 'admin', 'role' => 'admin', 'banned' => 0]);
        $this->ownerUser = User::factory()->create(['user_type' => 'vendor', 'banned' => 0]);
        $this->owner = Vendor::create(['user_id' => $this->ownerUser->id, 'shop_name' => 'Old Shop']);
        $this->newVendor = Vendor::create(['user_id' => User::factory()->create(['user_type' => 'vendor', 'banned' => 0])->id, 'shop_name' => 'New Shop']);
        $category = Category::create(['name' => 'Kitchen', 'slug' => 'kitchen']);
        $this->product = Product::create([
            'name' => 'Cooker', 'slug' => 'cooker', 'vendor_id' => $this->owner->id,
            'category_id' => $category->id, 'unit_price' => 1050, 'admin_price' => 1101, 'current_stock' => 5,
        ]);
        $this->product->update(['sku' => "p{$this->product->id}v{$this->owner->id}"]); // auto SKU, as create/copy makes it
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($user, ['basic'])['plain']];
    }

    private function change(User $user, array $body)
    {
        return $this->postJson("/api/products/{$this->product->id}/vendor-change", $body, $this->headers($user));
    }

    private function cartItem(): CartItem
    {
        $cart = Cart::create(['user_id' => User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0])->id]);

        return CartItem::create(['cart_id' => $cart->id, 'product_id' => $this->product->id, 'shop_id' => $this->owner->id,
            'qty' => 1, 'unit_price' => 1050, 'admin_price' => 1101, 'reseller_price' => 1500]);
    }

    private function orderWithProduct(int $status): OrderItem
    {
        $dropshipper = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $order = Order::create(['user_id' => $dropshipper->id, 'vendor_id' => $this->owner->id, 'order_number' => 'O-' . uniqid(),
            'status' => $status, 'customer_name' => 'C', 'customer_phone' => '017', 'total' => 1500]);

        return OrderItem::create(['order_id' => $order->id, 'product_id' => $this->product->id, 'shop_id' => $this->owner->id,
            'product_name' => 'Cooker', 'sku' => 'x', 'unit_price' => 1050, 'qty' => 1, 'line_total' => 1050, 'status' => 1]);
    }

    public function test_preview_shows_what_will_change_without_changing_anything(): void
    {
        $this->cartItem();
        $this->orderWithProduct(2);   // open
        $this->orderWithProduct(9);   // completed

        $preview = $this->getJson("/api/products/{$this->product->id}/vendor-change/preview?vendor_id={$this->newVendor->id}", $this->headers($this->admin))
            ->assertOk()->json('data');

        $this->assertSame("p{$this->product->id}v{$this->newVendor->id}", $preview['sku']['after']);
        $this->assertTrue($preview['sku']['is_auto']);
        $this->assertSame(1, $preview['cart_items_to_move']);
        $this->assertSame(1, $preview['open_orders']);
        $this->assertSame(2, $preview['all_orders']);
        $this->assertSame($this->owner->id, $this->product->fresh()->vendor_id, 'preview changes nothing');
    }

    public function test_admin_change_moves_product_auto_sku_and_open_carts_but_not_old_orders(): void
    {
        $cartItem = $this->cartItem();
        $checkedOut = $this->cartItem();
        Cart::whereKey($checkedOut->cart_id)->update(['status' => 'checked_out']);
        $oldOrderItem = $this->orderWithProduct(2);

        $this->change($this->admin, ['vendor_id' => (string) $this->newVendor->id, 'reason' => 'Supplier changed'])
            ->assertOk()
            ->assertJsonPath('data.product.vendor_id', $this->newVendor->id)
            ->assertJsonPath('data.change.cart_items_moved', 1)
            ->assertJsonPath('data.change.open_orders_left', 1);

        $product = $this->product->fresh();
        $this->assertSame($this->newVendor->id, $product->vendor_id);
        $this->assertSame("p{$product->id}v{$this->newVendor->id}", $product->sku);
        $this->assertSame($this->newVendor->id, $cartItem->fresh()->shop_id, 'next checkout goes to the new vendor');
        $this->assertSame($this->owner->id, (int) $checkedOut->fresh()->shop_id, 'checked-out carts are history');
        $this->assertSame($this->owner->id, (int) $oldOrderItem->fresh()->shop_id, 'existing orders stay with the vendor who had them');

        $this->assertDatabaseHas('product_vendor_changes', [
            'product_id' => $product->id, 'from_vendor_id' => $this->owner->id, 'to_vendor_id' => $this->newVendor->id,
            'old_sku' => "p{$product->id}v{$this->owner->id}", 'reason' => 'Supplier changed', 'changed_by' => $this->admin->id,
        ]);
        $this->getJson("/api/products/{$product->id}/vendor-changes", $this->headers($this->admin))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_custom_sku_is_kept_and_sku_update_can_be_turned_off(): void
    {
        $this->product->update(['sku' => 'PRESTIGE-75L']);
        $this->change($this->admin, ['vendor_id' => $this->newVendor->id, 'reason' => 'Move'])->assertOk();
        $this->assertSame('PRESTIGE-75L', $this->product->fresh()->sku);

        $this->product->update(['sku' => "p{$this->product->id}v{$this->newVendor->id}"]);
        $this->change($this->admin, ['vendor_id' => $this->owner->id, 'reason' => 'Back', 'update_sku' => false])->assertOk();
        $this->assertSame("p{$this->product->id}v{$this->newVendor->id}", $this->product->fresh()->sku);
    }

    public function test_vendor_cannot_change_the_vendor(): void
    {
        $this->change($this->ownerUser, ['vendor_id' => $this->newVendor->id, 'reason' => 'Mine now'])->assertForbidden();
        $this->assertSame($this->owner->id, $this->product->fresh()->vendor_id);
    }

    public function test_reason_and_a_real_vendor_are_required(): void
    {
        $this->change($this->admin, ['vendor_id' => $this->newVendor->id])->assertUnprocessable();
        $this->change($this->admin, ['vendor_id' => 999999, 'reason' => 'x x x'])->assertUnprocessable();
        $this->change($this->admin, ['vendor_id' => $this->owner->id, 'reason' => 'same'])->assertUnprocessable();
        $this->assertSame($this->owner->id, $this->product->fresh()->vendor_id);
    }

    public function test_normal_product_update_cannot_change_the_vendor(): void
    {
        $this->postJson("/api/products/update/{$this->product->id}", ['vendor_id' => $this->newVendor->id, 'name' => 'X'], $this->headers($this->admin))
            ->assertUnprocessable();
        $this->assertSame($this->owner->id, $this->product->fresh()->vendor_id);

        // Saving other fields with the same vendor still works.
        $this->postJson("/api/products/update/{$this->product->id}", ['vendor_id' => (string) $this->owner->id, 'name' => 'Cooker 2'], $this->headers($this->admin))
            ->assertOk();
        $this->assertSame('Cooker 2', $this->product->fresh()->name);
    }
}
