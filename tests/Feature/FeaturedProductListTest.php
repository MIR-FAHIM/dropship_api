<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeaturedProductListTest extends TestCase
{
    use RefreshDatabase;

    public function test_featured_list_always_requires_featured_products(): void
    {
        $user = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $category = Category::create(['name' => 'Kitchen', 'slug' => 'kitchen']);
        $attributes = ['vendor_id' => $user->id, 'category_id' => $category->id, 'unit_price' => 100, 'admin_price' => 110];
        $featured = Product::create($attributes + ['name' => 'Featured kettle', 'slug' => 'featured-kettle', 'featured' => 1]);
        Product::create($attributes + ['name' => 'Regular kettle', 'slug' => 'regular-kettle', 'featured' => 0]);

        $this->withToken(ApiTokenService::create($user, ['basic'])['plain']);

        foreach (['', '?featured=0', '?search=kettle&per_page=1'] as $query) {
            $this->getJson('/api/products/list/featured'.$query)
                ->assertOk()
                ->assertJsonPath('data.total', 1)
                ->assertJsonPath('data.data.0.id', $featured->id)
                ->assertJsonCount(1, 'data.data');
        }
    }
}
