<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\OrderErrorLog;
use App\Models\Product;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCheckoutErrorLoggingTest extends TestCase
{
    use RefreshDatabase;

    private function createAuthUser(): array
    {
        $user = User::create([
            'name'      => 'Test Dropshipper',
            'email'     => 'dropshipper_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'customer',
            'user_type' => 'dropshipper',
            'banned'    => 0,
        ]);

        $tokenData = ApiTokenService::create($user, ['basic'], 30, 'test-token');

        return [$user, $tokenData['plain']];
    }

    public function test_checkout_validation_error_is_properly_logged_with_field_errors(): void
    {
        [$user, $token] = $this->createAuthUser();

        // user_id is missing from request body, but user is authenticated
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/orders/checkout', [
                'customer_name' => 'John Doe',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'status'  => 'failed',
                'message' => 'Validation failed',
            ]);

        $log = OrderErrorLog::latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals($user->id, $log->user_id);
        $this->assertStringContainsString('Checkout validation failed', $log->message);
        $this->assertStringContainsString('Errors:', $log->message);
        $this->assertStringContainsString('user_id', $log->message);
    }

    public function test_checkout_cart_not_found_is_properly_logged(): void
    {
        [$user, $token] = $this->createAuthUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/orders/checkout', [
                'user_id' => $user->id,
            ]);

        $response->assertStatus(404);

        $log = OrderErrorLog::latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals($user->id, $log->user_id);
        $this->assertEquals('Checkout failed: active cart not found', $log->message);
    }

    public function test_checkout_cart_empty_is_properly_logged(): void
    {
        [$user, $token] = $this->createAuthUser();

        Cart::create([
            'user_id' => $user->id,
            'status'  => 'active',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/orders/checkout', [
                'user_id' => $user->id,
            ]);

        $response->assertStatus(409);

        $log = OrderErrorLog::latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals($user->id, $log->user_id);
        $this->assertEquals('Checkout failed: cart is empty', $log->message);
    }

    public function test_checkout_reseller_price_less_than_admin_price_is_properly_logged(): void
    {
        [$user, $token] = $this->createAuthUser();

        $category = Category::create([
            'name'           => 'Electronics',
            'slug'           => 'electronics',
            'order_level'    => 1,
            'commision_rate' => 0,
        ]);

        $product = Product::create([
            'name'        => 'Wireless Mouse',
            'slug'        => 'wireless-mouse',
            'vendor_id'   => $user->id,
            'category_id' => $category->id,
            'unit_price'  => 100,
            'admin_price' => 120,
        ]);

        $cart = Cart::create([
            'user_id' => $user->id,
            'status'  => 'active',
        ]);

        $cartItem = CartItem::create([
            'cart_id'        => $cart->id,
            'product_id'     => $product->id,
            'qty'            => 1,
            'unit_price'     => 100,
            'admin_price'    => 120,
            'reseller_price' => 80, // Less than admin_price (120)!
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/orders/checkout', [
                'user_id'          => $user->id,
                'customer_name'    => 'Ali',
                'customer_phone'   => '01700000000',
                'shipping_address' => 'Dhaka',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'status'  => 'failed',
                'message' => 'Cart item reseller price can not be less than admin price',
            ]);

        $log = OrderErrorLog::latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals($user->id, $log->user_id);
        $this->assertStringContainsString('Cart item reseller price cannot be less than admin price', $log->message);

        $requestData = json_decode($log->request_data, true);
        $this->assertArrayHasKey('_error_context', $requestData);
        $this->assertEquals(120, $requestData['_error_context']['admin_price']);
        $this->assertEquals(80, $requestData['_error_context']['reseller_price']);
    }
}
