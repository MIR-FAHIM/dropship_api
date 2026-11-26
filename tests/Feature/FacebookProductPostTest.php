<?php

namespace Tests\Feature;

use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Upload;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Facebook connect phase 2: post a product (caption + up to 10 photos, now or scheduled) to my page(s). */
class FacebookProductPostTest extends TestCase
{
    use RefreshDatabase;

    private User $drop;
    private FacebookPage $page;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.facebook.app_secret' => 'app-secret', 'services.facebook.media_base_url' => 'https://cdn.test/storage']);
        $this->drop = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->page = FacebookPage::create(['user_id' => $this->drop->id, 'facebook_account_id' => 1, 'page_id' => 'P1', 'page_name' => 'My Shop',
            'page_access_token' => Crypt::encryptString('PAGE-TOKEN'), 'category' => 'Shopping', 'status' => 'connected']);

        $thumb = Upload::create(['file_name' => 'all/thumb.jpg']);
        $second = Upload::create(['file_name' => 'all/side.jpg']);
        $this->product = Product::create(['vendor_id' => 1, 'shop_id' => 1, 'name' => 'Mini Heater', 'slug' => 'mini-heater', 'unit_price' => 500, 'admin_price' => 600,
            'max_resell_price' => 990, 'description' => '<p>Warm &amp; <b>quiet</b></p>', 'thumbnail_img' => $thumb->id, 'category_id' => 1, 'brand_id' => 1]);
        ProductImage::create(['product_id' => $this->product->id, 'image' => $second->id, 'sort_order' => 1]);

        Http::fake(function (Request $r) {
            parse_str($r->body(), $f);
            if (($f['access_token'] ?? '') === 'DEAD') {
                return Http::response(['error' => ['message' => 'Session has expired', 'code' => 190]], 400);
            }
            if (str_contains($r->url(), '/P1/photos')) {
                return Http::response(['id' => 'PH' . substr(md5($f['url'] ?? ''), 0, 4)]);
            }

            return Http::response(['id' => 'P1_100']);
        });
    }

    private function h(User $u): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($u, ['basic'])['plain']];
    }

    private function publish(array $body, ?User $u = null)
    {
        return $this->postJson("/api/facebook/products/{$this->product->id}/publish", $body + ['page_ids' => [$this->page->id], 'caption' => 'Buy now'], $this->h($u ?? $this->drop));
    }

    public function test_draft_gives_product_photos_and_my_pages_without_tokens(): void
    {
        $res = $this->getJson("/api/facebook/products/{$this->product->id}/draft", $this->h($this->drop))->assertOk();
        $this->assertSame(['https://cdn.test/storage/all/thumb.jpg', 'https://cdn.test/storage/all/side.jpg'], $res->json('data.images'));
        $this->assertSame('Warm & quiet', $res->json('data.product.description'));
        $this->assertEquals(990, $res->json('data.product.suggested_price'));
        $this->assertSame('My Shop', $res->json('data.pages.0.name'));
        $this->assertStringNotContainsString('PAGE-TOKEN', $res->getContent());
    }

    public function test_posts_one_post_with_several_photos_now(): void
    {
        $this->publish(['image_indexes' => [0, 1, 7]])->assertOk()->assertJsonPath('data.results.0.ok', true)
            ->assertJsonPath('data.results.0.post_url', 'https://www.facebook.com/P1_100');

        // Two photos uploaded unpublished, then ONE feed post with both attached (index 7 doesn't exist → ignored).
        Http::assertSentCount(3);
        Http::assertSent(function (Request $r) {
            parse_str($r->body(), $f);

            return str_contains($r->url(), '/P1/feed') && $f['message'] === 'Buy now' && isset($f['attached_media'][0], $f['attached_media'][1])
                && !isset($f['published']) && $f['appsecret_proof'] === hash_hmac('sha256', 'PAGE-TOKEN', 'app-secret');
        });
        $post = FacebookPost::first();
        $this->assertSame('published', $post->status);
        $this->assertSame(2, $post->image_count);
        $this->assertSame($this->drop->id, $post->user_id);
    }

    public function test_schedule_rules_and_scheduled_post(): void
    {
        $this->publish(['scheduled_at' => now()->addMinutes(3)->toIso8601String()])->assertStatus(422);
        $this->publish(['scheduled_at' => now()->addDays(40)->toIso8601String()])->assertStatus(422);

        $at = now()->addHours(2)->startOfMinute();
        $this->publish(['scheduled_at' => $at->toIso8601String(), 'image_indexes' => [0]])->assertOk()->assertJsonPath('data.results.0.status', 'scheduled');
        Http::assertSent(function (Request $r) use ($at) {
            parse_str($r->body(), $f);

            return str_contains($r->url(), '/P1/feed') && $f['published'] === 'false' && (int) $f['scheduled_publish_time'] === $at->timestamp;
        });
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/photos') && str_contains($r->body(), 'temporary=true'));
        $this->assertSame('scheduled', FacebookPost::first()->status);
    }

    public function test_someone_elses_page_is_refused(): void
    {
        $other = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->publish([], $other)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_a_dead_token_marks_the_page_for_reconnect(): void
    {
        $this->page->update(['page_access_token' => Crypt::encryptString('DEAD')]);
        $this->publish([])->assertStatus(502)->assertJsonPath('data.results.0.reason', 'needs_reconnect');
        $this->assertSame('needs_reconnect', $this->page->fresh()->status);
        $this->assertSame('failed', FacebookPost::first()->status);

        // Next try doesn't even call Facebook.
        $this->publish([])->assertStatus(502)->assertJsonPath('data.results.0.reason', 'needs_reconnect');
        $this->assertSame(1, FacebookPost::count());
    }
}
