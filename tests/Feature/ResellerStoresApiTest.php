<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerStoresApiTest extends TestCase
{
    use RefreshDatabase;

    private function createProduct(array $attributes): Product
    {
        return Product::create(array_merge([
            'unit_price'  => 100,
            'admin_price' => 120,
            'published'   => 1,
            'approved'    => 1,
        ], $attributes));
    }

    private function createStoreScenario(): array
    {
        $category = Category::create([
            'name' => 'General Electronics',
            'slug' => 'general-electronics',
        ]);

        // 1. Qualified Vendor: Active, User not banned, has 3 products
        $user1 = User::create([
            'name'      => 'Apex Electronics User',
            'email'     => 'apex@example.com',
            'password'  => bcrypt('password123'),
            'user_type' => 'vendor',
            'banned'    => 0,
            'phone'     => '01711111111',
        ]);

        $vendor1 = Vendor::create([
            'user_id'     => $user1->id,
            'shop_name'   => 'Apex Electronics',
            'owner_name'  => 'Rahim Apex',
            'shop_type'   => 'Wholesaler',
            'logo'        => 'logos/apex.png',
            'banner'      => 'banners/apex_banner.jpg',
            'is_active'   => true,
            'zone'        => 'Mirpur',
            'whatsapp'    => '01711111111',
            'description' => 'Top electronics wholesaler',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->createProduct([
                'category_id'  => $category->id,
                'name'         => "Apex Product $i",
                'slug'         => "apex-product-$i",
                'vendor_id'    => $vendor1->id,
                'published'    => 1,
                'unit_price'   => 500 * $i,
                'admin_price'  => 550 * $i,
                'purchase_price' => 400 * $i,
            ]);
        }

        // 2. Vendor with exactly 1 product (should be EXCLUDED because rule is > 1)
        $user2 = User::create([
            'name'      => 'Single Item Shop User',
            'email'     => 'single@example.com',
            'password'  => bcrypt('password123'),
            'user_type' => 'vendor',
            'banned'    => 0,
        ]);

        $vendor2 = Vendor::create([
            'user_id'     => $user2->id,
            'shop_name'   => 'Single Item Shop',
            'owner_name'  => 'Karim Single',
            'shop_type'   => 'Retailer',
            'is_active'   => true,
        ]);

        $this->createProduct([
            'category_id'  => $category->id,
            'name'         => 'Single Lone Product',
            'slug'         => 'single-lone-product',
            'vendor_id'    => $vendor2->id,
            'published'    => 1,
            'unit_price'   => 1200,
            'admin_price'  => 1300,
        ]);

        // 3. Vendor with 0 products (should be EXCLUDED)
        $user3 = User::create([
            'name'      => 'Empty Shop User',
            'email'     => 'empty@example.com',
            'password'  => bcrypt('password123'),
            'user_type' => 'vendor',
            'banned'    => 0,
        ]);

        $vendor3 = Vendor::create([
            'user_id'     => $user3->id,
            'shop_name'   => 'Empty Shop',
            'shop_type'   => 'Distributor',
            'is_active'   => true,
        ]);

        // 4. Inactive vendor with 5 products (should be EXCLUDED)
        $user4 = User::create([
            'name'      => 'Inactive Shop User',
            'email'     => 'inactive@example.com',
            'password'  => bcrypt('password123'),
            'user_type' => 'vendor',
            'banned'    => 0,
        ]);

        $vendor4 = Vendor::create([
            'user_id'     => $user4->id,
            'shop_name'   => 'Inactive Closed Shop',
            'shop_type'   => 'Wholesaler',
            'is_active'   => false, // Inactive
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $this->createProduct([
                'category_id'  => $category->id,
                'name'         => "Inactive Product $i",
                'slug'         => "inactive-product-$i",
                'vendor_id'    => $vendor4->id,
                'published'    => 1,
                'unit_price'   => 100 * $i,
                'admin_price'  => 120 * $i,
            ]);
        }

        // 5. Vendor with 2 products, active vendor, but banned user (should be EXCLUDED)
        $user5 = User::create([
            'name'      => 'Banned Vendor User',
            'email'     => 'banned@example.com',
            'password'  => bcrypt('password123'),
            'user_type' => 'vendor',
            'banned'    => 1, // Banned
        ]);

        $vendor5 = Vendor::create([
            'user_id'     => $user5->id,
            'shop_name'   => 'Banned Shop',
            'shop_type'   => 'Manufacturer',
            'is_active'   => true,
        ]);

        for ($i = 1; $i <= 2; $i++) {
            $this->createProduct([
                'category_id'  => $category->id,
                'name'         => "Banned Product $i",
                'slug'         => "banned-product-$i",
                'vendor_id'    => $vendor5->id,
                'published'    => 1,
                'unit_price'   => 200 * $i,
                'admin_price'  => 220 * $i,
            ]);
        }

        // 6. Another qualified vendor: Active, not banned, has 2 products (> 1)
        $user6 = User::create([
            'name'      => 'Fashion Hub User',
            'email'     => 'fashion@example.com',
            'password'  => bcrypt('password123'),
            'user_type' => 'vendor',
            'banned'    => 0,
        ]);

        $vendor6 = Vendor::create([
            'user_id'     => $user6->id,
            'shop_name'   => 'Fashion Hub BD',
            'owner_name'  => 'Sumon Fashion',
            'shop_type'   => 'Manufacturer',
            'is_active'   => true,
            'zone'        => 'GEC',
        ]);

        for ($i = 1; $i <= 2; $i++) {
            $this->createProduct([
                'category_id'  => $category->id,
                'name'         => "Fashion Product $i",
                'slug'         => "fashion-product-$i",
                'vendor_id'    => $vendor6->id,
                'published'    => 1,
                'unit_price'   => 900 * $i,
                'admin_price'  => 950 * $i,
            ]);
        }

        return [
            'vendor1' => $vendor1,
            'vendor2' => $vendor2,
            'vendor3' => $vendor3,
            'vendor4' => $vendor4,
            'vendor5' => $vendor5,
            'vendor6' => $vendor6,
        ];
    }

    public function test_reseller_stores_endpoint_returns_only_active_vendors_with_more_than_one_product(): void
    {
        $vendors = $this->createStoreScenario();

        $response = $this->getJson('/api/reseller/stores');

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Stores fetched successfully',
            ]);

        $data = $response->json('data');

        // Only vendor1 (3 products) and vendor6 (2 products) should be returned!
        $this->assertCount(2, $data);

        $returnedIds = array_column($data, 'id');
        $this->assertContains($vendors['vendor1']->id, $returnedIds);
        $this->assertContains($vendors['vendor6']->id, $returnedIds);

        // Verify excluded vendors
        $this->assertNotContains($vendors['vendor2']->id, $returnedIds, 'Vendor with only 1 product must be excluded');
        $this->assertNotContains($vendors['vendor3']->id, $returnedIds, 'Vendor with 0 products must be excluded');
        $this->assertNotContains($vendors['vendor4']->id, $returnedIds, 'Inactive vendor must be excluded');
        $this->assertNotContains($vendors['vendor5']->id, $returnedIds, 'Banned vendor must be excluded');
    }

    public function test_reseller_stores_returns_vendortype_logo_and_banner_fields(): void
    {
        $vendors = $this->createStoreScenario();

        $response = $this->getJson('/api/reseller/stores');
        $response->assertStatus(200);

        $data = $response->json('data');
        $apexStore = collect($data)->firstWhere('code', 's' . $vendors['vendor1']->id);

        $this->assertNotNull($apexStore);
        $this->assertEquals('s' . $vendors['vendor1']->id, $apexStore['shop_name']);
        $this->assertEquals('s' . $vendors['vendor1']->id, $apexStore['name']);
        $this->assertEquals('Wholesaler', $apexStore['vendortype']);
        $this->assertStringContainsString('logos/apex.png', $apexStore['logo']);
        $this->assertStringContainsString('banners/apex_banner.jpg', $apexStore['banner']);
        $this->assertTrue($apexStore['is_active']);
        $this->assertEquals(3, $apexStore['total_products']);
        $this->assertEquals('s' . $apexStore['id'], $apexStore['code']);
    }

    public function test_alias_endpoints_work_identically(): void
    {
        $this->createStoreScenario();

        $res1 = $this->getJson('/api/vendors/stores');
        $res1->assertStatus(200);
        $this->assertCount(2, $res1->json('data'));

        $res2 = $this->getJson('/api/stores');
        $res2->assertStatus(200);
        $this->assertCount(2, $res2->json('data'));
    }

    public function test_filtering_by_vendortype_and_search_works(): void
    {
        $vendors = $this->createStoreScenario();

        // Filter by vendortype = Manufacturer
        $resFilter = $this->getJson('/api/reseller/stores?vendortype=Manufacturer');
        $resFilter->assertStatus(200);
        $dataFilter = $resFilter->json('data');
        $this->assertCount(1, $dataFilter);
        $this->assertEquals('s' . $vendors['vendor6']->id, $dataFilter[0]['shop_name']);
        $this->assertEquals('s' . $vendors['vendor6']->id, $dataFilter[0]['code']);

        // Search by keyword "Apex"
        $resSearch = $this->getJson('/api/reseller/stores?search=Apex');
        $resSearch->assertStatus(200);
        $dataSearch = $resSearch->json('data');
        $this->assertCount(1, $dataSearch);
        $this->assertEquals('s' . $vendors['vendor1']->id, $dataSearch[0]['shop_name']);
        $this->assertEquals('s' . $vendors['vendor1']->id, $dataSearch[0]['code']);
    }

    public function test_pagination_support(): void
    {
        $this->createStoreScenario();

        $res = $this->getJson('/api/reseller/stores?per_page=1&page=1');
        $res->assertStatus(200);

        $payload = $res->json('data');
        $this->assertArrayHasKey('data', $payload);
        $this->assertArrayHasKey('total', $payload);
        $this->assertEquals(2, $payload['total']);
        $this->assertCount(1, $payload['data']);
    }
}
