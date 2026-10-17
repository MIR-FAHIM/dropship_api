<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\LandingPageOrder;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\ProductClicks;
use App\Models\RelatedProduct;
use App\Models\ResellerProductPage;
use App\Models\ResellerStoreProfile;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicStoreProductPageTest extends TestCase
{
    use RefreshDatabase;

    private function createSeedData(): array
    {
        $reseller = User::create([
            'name'      => 'Top Dropshipper',
            'email'     => 'dropshipper_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'dropshipper',
            'user_type' => 'dropshipper',
            'banned'    => 0,
        ]);

        $category = Category::create([
            'name'           => 'Kitchen Appliances',
            'slug'           => 'kitchen-appliances',
            'order_level'    => 1,
            'commision_rate' => 0,
        ]);

        $product = Product::create([
            'name'             => 'Silver Crest Electric Grinder 1500 Watt',
            'slug'             => 'silver-crest-grinder-base',
            'added_by'         => 'admin',
            'vendor_id'        => $reseller->id,
            'category_id'      => $category->id,
            'unit_price'       => 500,
            'admin_price'      => 550,
            'max_resell_price' => 1200,
            'purchase_price'   => 450,
            'description'      => 'High speed stainless steel grinder',
            'published'        => 1,
            'approved'         => 1,
        ]);

        $storeProfile = ResellerStoreProfile::create([
            'reseller_id' => $reseller->id,
            'shop_name'   => 'Electro Gadget Store',
            'phone'       => '01712345678',
            'whatsapp'    => '01712345678',
        ]);

        $page = ResellerProductPage::create([
            'reseller_id'    => $reseller->id,
            'product_id'     => $product->id,
            'slug'           => 'silver-crest-electric-grinder-1500-watt-stainless-steel-smart-electric-grinder-7',
            'selling_price'  => 850,
            'discount_price' => 799,
            'custom_title'   => 'Silver Crest Electric Grinder 1500 Watt',
            'delivery_charge'=> 60,
            'template_id'    => 'modern',
            'published_status'=> 'published',
        ]);

        return [$reseller, $product, $storeProfile, $page];
    }

    public function test_public_can_get_reseller_product_page_by_slug_without_token(): void
    {
        [$reseller, $product, $storeProfile, $page] = $this->createSeedData();

        $response = $this->getJson("/api/reseller-product-pages/slug/{$page->slug}");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'id' => $page->id,
                    'slug' => $page->slug,
                    'selling_price' => 850,
                    'discount_price' => 799,
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                    ],
                    'reseller' => [
                        'shop_name' => 'Electro Gadget Store',
                    ],
                ],
            ]);

        // Verify guest click was tracked
        $this->assertDatabaseHas('product_clicks', [
            'product_id' => $product->id,
            'page'       => 'reseller_product_page',
            'is_guest'   => 1,
        ]);
    }

    public function test_public_can_get_reseller_product_page_by_product_slug_fallback(): void
    {
        [$reseller, $product, $storeProfile, $page] = $this->createSeedData();

        // Query using product's base slug instead of reseller custom slug
        $response = $this->getJson("/api/reseller-product-pages/slug/{$product->slug}");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'id' => $page->id,
                    'slug' => $page->slug,
                    'product_id' => $product->id,
                ],
            ]);
    }

    public function test_public_can_get_reseller_product_page_details_by_id_without_token(): void
    {
        [$reseller, $product, $storeProfile, $page] = $this->createSeedData();

        $response = $this->getJson("/api/reseller-product-pages/{$page->id}");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'id' => $page->id,
                    'slug' => $page->slug,
                ],
            ]);
    }

    public function test_public_can_get_reseller_store_profile_without_token(): void
    {
        [$reseller, $product, $storeProfile] = $this->createSeedData();

        $response = $this->getJson("/api/reseller-store-profiles/reseller/{$reseller->id}");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'reseller_id' => $reseller->id,
                    'shop_name' => 'Electro Gadget Store',
                ],
            ]);
    }

    public function test_public_can_create_landing_page_order_without_token(): void
    {
        [$reseller, $product, $storeProfile, $page] = $this->createSeedData();

        $payload = [
            'reseller_product_page_id' => $page->id,
            'customer_name'            => 'Rahim Ahmed',
            'customer_phone'           => '01811223344',
            'customer_address'         => 'House 12, Road 4, Dhanmondi, Dhaka',
            'quantity'                 => 2,
        ];

        $response = $this->postJson('/api/landing-page-orders/create', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'message' => 'Landing page order created successfully',
                'data' => [
                    'customer_name' => 'Rahim Ahmed',
                    'customer_phone' => '01811223344',
                    'reseller_id' => $reseller->id,
                    'product_id' => $product->id,
                ],
            ]);

        $orderId = $response->json('data.id');

        // Verify order details can also be fetched publicly by buyer
        $detailsResponse = $this->getJson("/api/landing-page-orders/details/{$orderId}");
        $detailsResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'id' => $orderId,
                    'customer_name' => 'Rahim Ahmed',
                ],
            ]);
    }

    public function test_public_can_get_product_attributes_reviews_and_related_without_token(): void
    {
        [$reseller, $product] = $this->createSeedData();

        $attribute = Attribute::create(['name' => 'Color']);
        $value = AttributeValue::create([
            'attribute_id' => $attribute->id,
            'value'        => 'Silver',
        ]);

        ProductAttribute::create([
            'product_id'         => $product->id,
            'attribute_id'       => $attribute->id,
            'attribute_value_id' => $value->id,
            'stock'              => 50,
            'is_active'          => 1,
        ]);

        Review::create([
            'product_id' => $product->id,
            'user_id'    => $reseller->id,
            'star_count' => 5,
            'comment'    => 'Great electric grinder, works smoothly!',
        ]);

        // Product attributes by product
        $attrResponse = $this->getJson("/api/product-attributes/by-product/{$product->id}");
        $attrResponse->assertStatus(200);

        // Reviews by product
        $reviewResponse = $this->getJson("/api/reviews/product/{$product->id}");
        $reviewResponse->assertStatus(200);

        // Related products
        $relatedResponse = $this->getJson("/api/related-products/list/{$product->id}");
        $relatedResponse->assertStatus(200);
    }
}
