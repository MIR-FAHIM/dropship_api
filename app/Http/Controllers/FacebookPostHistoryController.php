<?php

namespace App\Http\Controllers;

use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Service\FacebookApiException;
use App\Service\FacebookGraphService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dropshipper → Facebook পেজ → পোস্ট হিস্টোরি (Facebook connect, phase 3).
 *
 *  GET    /facebook/my-posts                 → my posts (status filter), with likes / comments / shares
 *  POST   /facebook/my-posts/refresh-stats   → fetch fresh numbers from Facebook (up to 20 posts)
 *  DELETE /facebook/my-posts/{id}            → cancel a scheduled post before it goes live
 */
class FacebookPostHistoryController extends Controller
{
    private const REFRESH_LIMIT = 20;
    private const STATUSES = ['published', 'scheduled', 'failed', 'cancelled', 'removed'];

    public function __construct(private readonly FacebookGraphService $graph)
    {
    }

    private function userId(Request $request): int
    {
        return (int) ($request->attributes->get('api_user') ?? $request->user())->id;
    }

    /** My posts: made by me, or on my page (older rows have no user_id). */
    private function mine(Request $request): Builder
    {
        $uid = $this->userId($request);

        return FacebookPost::query()->where(fn ($q) => $q->where('user_id', $uid)
            ->orWhereHas('facebookPage', fn ($p) => $p->where('user_id', $uid)));
    }

    /** GET /facebook/my-posts?status=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : null;
        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));

        $counts = $this->mine($request)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $page = $this->mine($request)->with(['facebookPage', 'product.primaryImage'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->paginate($perPage);

        return response()->json(['status' => 'success', 'message' => 'Your Facebook posts', 'data' => [
            'counts' => [
                'all' => (int) $counts->sum(),
                'published' => (int) ($counts['published'] ?? 0) + (int) ($counts['republished'] ?? 0),
                'scheduled' => (int) ($counts['scheduled'] ?? 0),
                'failed' => (int) ($counts['failed'] ?? 0),
            ],
            'totals' => [
                'likes' => (int) $this->mine($request)->sum('likes_count'),
                'comments' => (int) $this->mine($request)->sum('comments_count'),
                'shares' => (int) $this->mine($request)->sum('shares_count'),
            ],
            'posts' => collect($page->items())->map(fn (FacebookPost $p) => $this->present($p))->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]]);
    }

    /** POST /facebook/my-posts/refresh-stats {ids?: [...]} */
    public function refreshStats(Request $request): JsonResponse
    {
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));
        $posts = $this->mine($request)->with('facebookPage')
            ->whereIn('status', ['published', 'scheduled', 'republished'])->where('fb_post_id', '!=', '')
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->orderByDesc('id')->limit(self::REFRESH_LIMIT)->get();

        $updated = 0;
        foreach ($posts as $post) {
            $page = $post->facebookPage;
            if (!$page || !$page->isUsable()) {
                continue;
            }
            try {
                $s = $this->graph->postStats($page, $post->fb_post_id);
                $changes = [
                    'likes_count' => $s['likes'], 'comments_count' => $s['comments'], 'shares_count' => $s['shares'],
                    'permalink' => $s['permalink'] ?: $post->permalink, 'stats_updated_at' => now(),
                ];
                // A scheduled post that has gone live.
                if ($post->status === 'scheduled' && $s['is_published']) {
                    $changes += ['status' => 'published', 'published_at' => $post->scheduled_at ?? now()];
                }
                $post->update($changes);
                $updated++;
            } catch (FacebookApiException $e) {
                if ($e->needsReconnect()) {
                    $page->markNeedsReconnect($e->getMessage());
                } elseif ($e->getCode() === 100) {
                    // Facebook says the post doesn't exist any more (deleted on Facebook).
                    $post->update(['status' => 'removed', 'error' => 'Deleted on Facebook', 'stats_updated_at' => now()]);
                }
            }
        }

        return response()->json(['status' => 'success', 'message' => "Updated {$updated} post(s)", 'data' => ['updated' => $updated]]);
    }

    /** DELETE /facebook/my-posts/{id} — cancel a scheduled post. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $post = $this->mine($request)->with('facebookPage')->find($id);
        if (!$post) {
            return response()->json(['status' => 'failed', 'message' => 'Post not found'], 404);
        }
        if ($post->status !== 'scheduled') {
            return response()->json(['status' => 'failed', 'message' => 'Only a scheduled post can be cancelled here.'], 422);
        }
        if ($post->fb_post_id && $post->facebookPage) {
            try {
                $this->graph->deletePost($post->facebookPage, $post->fb_post_id);
            } catch (FacebookApiException $e) {
                if ($e->needsReconnect()) {
                    $post->facebookPage->markNeedsReconnect($e->getMessage());

                    return response()->json(['status' => 'failed', 'message' => 'Reconnect the page first, then cancel.'], 409);
                }
                if ($e->getCode() !== 100) { // 100 = already gone: fine
                    return response()->json(['status' => 'failed', 'message' => 'Facebook could not cancel it: ' . $e->getMessage()], 502);
                }
            }
        }
        $post->update(['status' => 'cancelled']);

        return response()->json(['status' => 'success', 'message' => 'Scheduled post cancelled', 'data' => $this->present($post->fresh(['facebookPage', 'product.primaryImage']))]);
    }

    private function present(FacebookPost $p): array
    {
        $file = $p->product?->primaryImage?->file_name;
        $base = rtrim((string) config('services.facebook.media_base_url'), '/');

        return [
            'id' => $p->id,
            'status' => $p->status === 'republished' ? 'published' : $p->status,
            'caption' => $p->caption,
            'image_count' => (int) $p->image_count,
            'scheduled_at' => $p->scheduled_at?->toIso8601String(),
            'published_at' => $p->published_at?->toIso8601String(),
            'created_at' => $p->created_at ? (string) $p->created_at : null,
            'error' => $p->error,
            'likes' => (int) $p->likes_count,
            'comments' => (int) $p->comments_count,
            'shares' => (int) $p->shares_count,
            'stats_updated_at' => $p->stats_updated_at?->toIso8601String(),
            'post_url' => $p->permalink ?: ($p->fb_post_id ? 'https://www.facebook.com/' . $p->fb_post_id : null),
            'page' => $p->facebookPage ? ['id' => $p->facebookPage->id, 'name' => $p->facebookPage->page_name, 'picture_url' => $p->facebookPage->picture_url, 'status' => $p->facebookPage->status] : null,
            'product' => $p->product ? [
                'id' => $p->product->id,
                'name' => $p->product->name,
                'image' => $file ? (preg_match('#^https?://#i', $file) ? $file : $base . '/' . ltrim($file, '/')) : null,
            ] : null,
        ];
    }
}
