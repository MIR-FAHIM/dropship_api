<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCreateTest extends TestCase
{
    use RefreshDatabase;

    private function createAuthUser(): array
    {
        $user = User::create([
            'name'      => 'Admin User',
            'email'     => 'admin_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'user_type' => 'admin',
            'banned'    => 0,
        ]);

        $tokenData = ApiTokenService::create($user, ['basic', 'admin'], 30, 'admin-token');

        return [$user, $tokenData['plain']];
    }

    public function test_create_product_without_todays_deal_and_optional_flags_succeeds(): void
    {
        [$user, $token] = $this->createAuthUser();

        $category = Category::create([
            'name'           => 'Water Tanks',
            'slug'           => 'water-tanks',
            'order_level'    => 1,
            'commision_rate' => 0,
        ]);

        $payload = [
            'name'            => '5L AquaFold Water Tank Pro',
            'added_by'        => 'seller',
            'vendor_id'       => $user->id,
            'category_id'     => $category->id,
            'unit_price'      => 300,
            'admin_price'     => 315,
            'max_resell_price'=> 600,
            'purchase_price'  => 300,
            'description'     => 'Quality water tank',
            // Notice: todays_deal, featured, seller_featured, digital, etc. are omitted
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/products/create', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Product created successfully',
            ]);

        $product = Product::where('name', '5L AquaFold Water Tank Pro')->first();
        $this->assertNotNull($product);
        $this->assertEquals(0, $product->todays_deal);
        $this->assertEquals(0, $product->featured);
        $this->assertEquals(0, $product->seller_featured);
        $this->assertEquals(1, $product->published);
        $this->assertEquals('quantity', $product->stock_visibility_state);
        $this->assertEquals('flat_rate', $product->shipping_type);
        $this->assertEquals(0.00, $product->shipping_cost);
    }

    public function test_create_product_with_todays_deal_true(): void
    {
        [$user, $token] = $this->createAuthUser();

        $category = Category::create([
            'name'           => 'Bottles',
            'slug'           => 'bottles',
            'order_level'    => 1,
            'commision_rate' => 0,
        ]);

        $payload = [
            'name'        => 'Special Deal Product',
            'vendor_id'   => $user->id,
            'category_id' => $category->id,
            'unit_price'  => 150,
            'todays_deal' => true,
            'featured'    => true,
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/products/create', $payload);

        $response->assertStatus(201);

        $product = Product::where('name', 'Special Deal Product')->first();
        $this->assertNotNull($product);
        $this->assertEquals(1, $product->todays_deal);
        $this->assertEquals(1, $product->featured);
    }
}
