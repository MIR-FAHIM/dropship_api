<?php

namespace Tests\Feature;

use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\User;
use App\Service\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Facebook connect phase 3: post history, likes/comments/shares, cancel scheduled, reconnect alerts. */
class FacebookPostHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $drop;
    private FacebookPage $page;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.facebook.app_secret' => 'app-secret']);
        $this->drop = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->page = $this->makePage($this->drop, 'P1', 'PAGE-TOKEN');
    }

    private function makePage(User $u, string $pageId, string $token): FacebookPage
    {
        return FacebookPage::create(['user_id' => $u->id, 'facebook_account_id' => 1, 'page_id' => $pageId, 'page_name' => "Shop {$pageId}",
            'page_access_token' => Crypt::encryptString($token), 'category' => 'Shopping', 'status' => 'connected']);
    }

    private function fbPost(FacebookPage $page, string $status, string $fbId = 'P1_1', array $extra = []): FacebookPost
    {
        return FacebookPost::create(['facebook_page_id' => $page->id, 'product_id' => 1, 'user_id' => $page->user_id, 'caption' => 'Buy now',
            'fb_post_id' => $fbId, 'status' => $status, 'image_count' => 1] + $extra);
    }

    private function h(User $u): array
    {
        return ['Authorization' => 'Bearer ' . ApiTokenService::create($u, ['basic'])['plain']];
    }

    public function test_history_shows_only_my_posts_with_counts_and_no_tokens(): void
    {
        $this->fbPost($this->page, 'published', 'P1_1', ['likes_count' => 5, 'comments_count' => 2]);
        $this->fbPost($this->page, 'scheduled', 'P1_2');
        $this->fbPost($this->page, 'failed', '');
        $other = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->fbPost($this->makePage($other, 'P9', 'OTHER-TOKEN'), 'published', 'P9_1');

        $res = $this->getJson('/api/facebook/my-posts', $this->h($this->drop))->assertOk();
        $this->assertCount(3, $res->json('data.posts'));
        $this->assertSame(['all' => 3, 'published' => 1, 'scheduled' => 1, 'failed' => 1], $res->json('data.counts'));
        $this->assertSame(5, $res->json('data.totals.likes'));
        $this->assertStringNotContainsString('PAGE-TOKEN', $res->getContent());

        $this->getJson('/api/facebook/my-posts?status=scheduled', $this->h($this->drop))->assertOk()->assertJsonCount(1, 'data.posts');
    }

    public function test_refresh_stats_saves_numbers_and_marks_scheduled_as_live(): void
    {
        $live = $this->fbPost($this->page, 'published', 'P1_1');
        $sched = $this->fbPost($this->page, 'scheduled', 'P1_2');
        $gone = $this->fbPost($this->page, 'published', 'P1_3');
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'P1_3')) {
                return Http::response(['error' => ['message' => 'Object does not exist', 'code' => 100]], 400);
            }

            return Http::response(['is_published' => true, 'permalink_url' => 'https://facebook.com/x',
                'reactions' => ['summary' => ['total_count' => 12]], 'comments' => ['summary' => ['total_count' => 3]], 'shares' => ['count' => 1]]);
        });

        $this->postJson('/api/facebook/my-posts/refresh-stats', [], $this->h($this->drop))->assertOk()->assertJsonPath('data.updated', 2);

        $this->assertSame([12, 3, 1], [$live->fresh()->likes_count, $live->fresh()->comments_count, $live->fresh()->shares_count]);
        $this->assertSame('published', $sched->fresh()->status);
        $this->assertSame('removed', $gone->fresh()->status);
        Http::assertSent(fn (Request $r) => ($r->data()['appsecret_proof'] ?? null) === hash_hmac('sha256', 'PAGE-TOKEN', 'app-secret'));
    }

    public function test_cancel_scheduled_deletes_on_facebook_and_only_mine(): void
    {
        Http::fake(['*' => Http::response(['success' => true])]);
        $sched = $this->fbPost($this->page, 'scheduled', 'P1_2');
        $live = $this->fbPost($this->page, 'published', 'P1_1');

        $this->deleteJson("/api/facebook/my-posts/{$live->id}", [], $this->h($this->drop))->assertStatus(422);
        $other = User::factory()->create(['user_type' => 'dropshipper', 'banned' => 0]);
        $this->deleteJson("/api/facebook/my-posts/{$sched->id}", [], $this->h($other))->assertNotFound();

        $this->deleteJson("/api/facebook/my-posts/{$sched->id}", [], $this->h($this->drop))->assertOk()->assertJsonPath('data.status', 'cancelled');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/P1_2'));
        $this->assertSame('cancelled', $sched->fresh()->status);
    }

    public function test_daily_check_marks_dead_page_and_notifies_once(): void
    {
        $dead = $this->makePage($this->drop, 'P2', 'DEAD');
        Http::fake(function (Request $r) {
            return ($r->data()['access_token'] ?? '') === 'DEAD'
                ? Http::response(['error' => ['message' => 'Session has expired', 'code' => 190]], 400)
                : Http::response(['id' => 'P1', 'name' => 'Shop']);
        });

        $this->artisan('facebook:check-pages')->assertSuccessful();
        $this->artisan('facebook:check-pages')->assertSuccessful();

        $this->assertSame('needs_reconnect', $dead->fresh()->status);
        $this->assertSame('connected', $this->page->fresh()->status);
        $this->assertSame(1, DB::table('notifications')->where('send_to', $this->drop->id)->where('module', 'facebook')->count());
    }

    public function test_facebook_hiccup_does_not_mark_page(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Temporary issue', 'code' => 2]], 500)]);
        $this->artisan('facebook:check-pages')->assertSuccessful();
        $this->assertSame('connected', $this->page->fresh()->status);
    }
}
