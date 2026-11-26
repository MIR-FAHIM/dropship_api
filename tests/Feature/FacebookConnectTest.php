<?php

namespace Tests\Feature;

use App\Models\FacebookAccount;
use App\Models\FacebookPage;
use App\Models\FeedPost;
use App\Models\Product;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** One-click Facebook page connect for dropshippers, and the security fixes around it. */
class FacebookConnectTest extends TestCase
{
    use RefreshDatabase;

    private User $drop;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.facebook.app_id' => '123',
            'services.facebook.app_secret' => 'app-secret',
            'services.facebook.graph_version' => 'v21.0',
            'services.facebook.redirect_uri' => 'https://api.test/api/facebook/connect/callback',
            'services.facebook.frontend_return_url' => 'https://panel.test/app/facebook-pages',
        ]);
        $this->drop = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->other = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);

        Http::fake(function (Request $r) {
            $url = $r->url();
            if (str_contains($url, '/oauth/access_token')) {
                return str_contains($url, 'fb_exchange_token')
                    ? Http::response(['access_token' => 'LONG-USER-TOKEN', 'expires_in' => 5184000])
                    : Http::response(['access_token' => 'SHORT-USER-TOKEN']);
            }
            if (str_contains($url, '/me/accounts')) {
                return Http::response(['data' => [
                    ['id' => 'P1', 'name' => 'My Shop', 'category' => 'Shopping', 'access_token' => 'PAGE-TOKEN-1', 'tasks' => ['MANAGE', 'CREATE_CONTENT'], 'picture' => ['data' => ['url' => 'https://img/p1.jpg']]],
                    ['id' => 'P2', 'name' => 'Second', 'category' => 'Brand', 'access_token' => 'PAGE-TOKEN-2', 'tasks' => ['ANALYZE']],
                ]]);
            }
            if (str_contains($url, '/me?') || str_ends_with(parse_url($url, PHP_URL_PATH), '/me')) {
                return Http::response(['id' => 'FBUSER1', 'name' => 'Rahim']);
            }
            if (str_contains($url, '/photos') || str_contains($url, '/feed')) {
                parse_str($r->body(), $form);
                // Expired page token → Facebook error 190.
                return ($form['access_token'] ?? '') === 'EXPIRED'
                    ? Http::response(['error' => ['message' => 'Error validating access token', 'code' => 190]], 400)
                    : Http::response(['id' => 'P1_999', 'post_id' => 'P1_999']);
            }

            return Http::response(['id' => 'P1', 'name' => 'My Shop']);
        });
    }

    private function h(User $u): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($u, ['basic'])['plain']];
    }

    /** Runs start → Facebook callback, returns the pending key from the redirect. */
    private function connect(): string
    {
        $url = $this->getJson('/api/facebook/connect/start', $this->h($this->drop))->assertOk()->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('https://api.test/api/facebook/connect/callback', $q['redirect_uri']);
        $this->assertStringContainsString('pages_manage_posts', $q['scope']);

        $res = $this->get('/api/facebook/connect/callback?' . http_build_query(['state' => $q['state'], 'code' => 'abc']));
        $res->assertRedirect();
        parse_str((string) parse_url($res->headers->get('Location'), PHP_URL_QUERY), $back);
        $this->assertArrayHasKey('connect', $back);

        return $back['connect'];
    }

    public function test_one_click_connect_saves_chosen_pages_without_ever_returning_tokens(): void
    {
        $key = $this->connect();

        $pending = $this->getJson("/api/facebook/connect/pending/{$key}", $this->h($this->drop))->assertOk();
        $this->assertSame(['P1', 'P2'], array_column($pending->json('data'), 'page_id'));
        $this->assertTrue($pending->json('data.0.can_post'));
        $this->assertFalse($pending->json('data.1.can_post'));
        $this->assertStringNotContainsString('PAGE-TOKEN', $pending->getContent());

        // Another dropshipper can't use this key.
        $this->getJson("/api/facebook/connect/pending/{$key}", $this->h($this->other))->assertStatus(410);

        $saved = $this->postJson("/api/facebook/connect/pending/{$key}", ['page_ids' => ['P1']], $this->h($this->drop))->assertOk();
        $this->assertStringNotContainsString('PAGE-TOKEN', $saved->getContent());

        $page = FacebookPage::where('user_id', $this->drop->id)->first();
        $this->assertSame('P1', $page->page_id);
        $this->assertNotSame('PAGE-TOKEN-1', $page->page_access_token, 'stored encrypted');
        $this->assertSame('PAGE-TOKEN-1', $page->plainToken());
        $this->assertSame('LONG-USER-TOKEN', Crypt::decryptString(FacebookAccount::first()->access_token));

        // Key is single use.
        $this->getJson("/api/facebook/connect/pending/{$key}", $this->h($this->drop))->assertStatus(410);

        $mine = $this->getJson('/api/facebook/my-pages', $this->h($this->drop))->assertOk();
        $this->assertSame('My Shop', $mine->json('data.pages.0.name'));
        $this->assertStringNotContainsString('TOKEN', $mine->getContent());
        $this->assertStringNotContainsString('eyJ', $this->getJson('/api/facebook/pages', $this->h($this->drop))->getContent(), 'no encrypted token either');
    }

    public function test_callback_with_bad_state_or_cancel_goes_back_with_a_reason(): void
    {
        $this->get('/api/facebook/connect/callback?state=nope&code=x')->assertRedirect('https://panel.test/app/facebook-pages?fb_error=expired');

        $url = $this->getJson('/api/facebook/connect/start', $this->h($this->drop))->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->get('/api/facebook/connect/callback?' . http_build_query(['state' => $q['state'], 'error' => 'access_denied']))
            ->assertRedirect('https://panel.test/app/facebook-pages?fb_error=cancelled');
        // State is single use.
        $this->get('/api/facebook/connect/callback?' . http_build_query(['state' => $q['state'], 'code' => 'x']))
            ->assertRedirect('https://panel.test/app/facebook-pages?fb_error=expired');
    }

    public function test_not_configured_is_a_clear_message(): void
    {
        config(['services.facebook.app_id' => null]);
        $this->getJson('/api/facebook/connect/start', $this->h($this->drop))->assertStatus(503)->assertJsonPath('reason', 'not_configured');
    }

    public function test_user_id_in_the_request_no_longer_exposes_other_peoples_pages(): void
    {
        $this->postJson('/api/facebook/connect/pending/' . $this->connect(), ['page_ids' => ['P1']], $this->h($this->drop));

        $rows = $this->getJson('/api/facebook/pages?user_id=' . $this->drop->id, $this->h($this->other))->assertOk()->json('data.data');
        $this->assertSame([], $rows);
    }

    public function test_publish_decrypts_signs_and_flags_a_dead_token(): void
    {
        $this->postJson('/api/facebook/connect/pending/' . $this->connect(), ['page_ids' => ['P1']], $this->h($this->drop));
        $page = FacebookPage::first();
        $product = Product::create(['vendor_id' => 1, 'shop_id' => 1, 'name' => 'Fan', 'slug' => 'fan', 'unit_price' => 100, 'admin_price' => 120, 'price' => 200, 'category_id' => 1, 'brand_id' => 1, 'status' => 1, 'is_approved' => 1]);

        $this->postJson('/api/facebook/posts/publish', ['facebook_page_id' => $page->id, 'product_id' => $product->id, 'caption' => 'Buy now', 'image_url' => 'https://img/fan.jpg'], $this->h($this->drop))
            ->assertCreated()->assertJsonPath('data.fb_post_id', 'P1_999');
        Http::assertSent(function (Request $r) {
            if (!str_contains($r->url(), '/P1/photos')) {
                return false;
            }
            parse_str($r->body(), $f);

            return $f['access_token'] === 'PAGE-TOKEN-1' && $f['appsecret_proof'] === hash_hmac('sha256', 'PAGE-TOKEN-1', 'app-secret');
        });

        // Someone else can't post on this page.
        $this->postJson('/api/facebook/posts/publish', ['facebook_page_id' => $page->id, 'product_id' => $product->id], $this->h($this->other))->assertForbidden();

        // Token stops working → page marked for reconnect, clear message.
        $page->update(['page_access_token' => Crypt::encryptString('EXPIRED')]);
        $this->postJson('/api/facebook/posts/publish', ['facebook_page_id' => $page->id, 'product_id' => $product->id, 'image_url' => 'https://img/fan.jpg'], $this->h($this->drop))
            ->assertStatus(502)->assertJsonPath('errors.reason', 'needs_reconnect');
        $this->assertSame(FacebookPage::STATUS_NEEDS_RECONNECT, $page->fresh()->status);
        $this->postJson('/api/facebook/posts/publish', ['facebook_page_id' => $page->id, 'product_id' => $product->id], $this->h($this->drop))->assertStatus(409);
    }

    public function test_share_to_page_ignores_a_spoofed_user_id(): void
    {
        $this->postJson('/api/facebook/connect/pending/' . $this->connect(), ['page_ids' => ['P1']], $this->h($this->drop));
        $post = FeedPost::create(['admin_id' => $this->other->id, 'title' => 'Hi', 'content' => 'Hello', 'status' => 'published', 'images' => []]);

        $this->postJson("/api/feed-posts/{$post->id}/share-to-page", ['facebook_page_id' => FacebookPage::first()->id, 'user_id' => $this->drop->id], $this->h($this->other))
            ->assertForbidden();
    }

    public function test_disconnect_wipes_the_token_and_data_deletion_is_verified(): void
    {
        $this->postJson('/api/facebook/connect/pending/' . $this->connect(), ['page_ids' => ['P1']], $this->h($this->drop));
        $page = FacebookPage::first();

        $this->deleteJson("/api/facebook/my-pages/{$page->id}", [], $this->h($this->other))->assertNotFound();
        $this->deleteJson("/api/facebook/my-pages/{$page->id}", [], $this->h($this->drop))->assertOk();
        $this->assertSame('', $page->fresh()->page_access_token);
        $this->assertSame([], $this->getJson('/api/facebook/my-pages', $this->h($this->drop))->json('data.pages'));

        // Meta data-deletion callback: forged signature rejected, real one clears the account token.
        $payload = rtrim(strtr(base64_encode(json_encode(['user_id' => 'FBUSER1', 'algorithm' => 'HMAC-SHA256'])), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, 'app-secret', true)), '+/', '-_'), '=');
        $this->postJson('/api/facebook/data-deletion', ['signed_request' => "bad.{$payload}"])->assertStatus(400);
        $this->postJson('/api/facebook/data-deletion', ['signed_request' => "{$sig}.{$payload}"])->assertOk()->assertJsonStructure(['url', 'confirmation_code']);
        $this->assertNull(FacebookAccount::first()->access_token);
    }
}
