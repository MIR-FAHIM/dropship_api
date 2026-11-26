<?php

namespace App\Http\Controllers;

use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\Product;
use App\Models\Upload;
use App\Service\FacebookApiException;
use App\Service\FacebookGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Dropshipper → product page → "Post to my Facebook page" (Facebook connect, phase 2).
 *
 *  GET  /facebook/products/{id}/draft    → what to post: name, short description, suggested price, the
 *                                          product's photos, and the dropshipper's connected pages
 *  POST /facebook/products/{id}/publish  → post to one or more connected pages, 0–10 photos, now or scheduled
 *
 * Photos can only be the product's own photos (picked by index), so the endpoint can't be used to push
 * arbitrary images. Pages must belong to the logged-in dropshipper and be connected.
 */
class FacebookProductPostController extends Controller
{
    private const MAX_PHOTOS = 10;
    private const MIN_SCHEDULE_MINUTES = 15;
    private const MAX_SCHEDULE_DAYS = 30;

    public function __construct(private readonly FacebookGraphService $graph)
    {
    }

    private function me(Request $request)
    {
        return $request->attributes->get('api_user') ?? $request->user();
    }

    /** GET /facebook/products/{id}/draft */
    public function draft(Request $request, int $id): JsonResponse
    {
        $product = Product::with(['primaryImage', 'images'])->find($id);
        if (!$product) {
            return response()->json(['status' => 'failed', 'message' => 'Product not found'], 404);
        }
        $pages = FacebookPage::where('user_id', $this->me($request)->id)->where('status', '!=', FacebookPage::STATUS_DISCONNECTED)
            ->orderBy('page_name')->get()
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->page_name, 'picture_url' => $p->picture_url, 'status' => $p->status ?: 'connected'])->values();

        return response()->json(['status' => 'success', 'message' => 'Post draft', 'data' => [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => Str::limit(trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $product->description)))), 400),
                'suggested_price' => $product->max_resell_price ? round((float) $product->max_resell_price) : null,
                'sku' => $product->sku,
            ],
            'images' => $this->imageUrls($product),
            'pages' => $pages,
            'limits' => ['max_photos' => self::MAX_PHOTOS, 'min_schedule_minutes' => self::MIN_SCHEDULE_MINUTES, 'max_schedule_days' => self::MAX_SCHEDULE_DAYS],
        ]]);
    }

    /** POST /facebook/products/{id}/publish {page_ids[], caption, image_indexes[], scheduled_at?} */
    public function publish(Request $request, int $id): JsonResponse
    {
        try {
            $data = $request->validate([
                'page_ids' => ['required', 'array', 'min:1', 'max:10'],
                'page_ids.*' => ['integer'],
                'caption' => ['required', 'string', 'max:5000'],
                'image_indexes' => ['nullable', 'array', 'max:' . self::MAX_PHOTOS],
                'image_indexes.*' => ['integer', 'min:0'],
                'scheduled_at' => ['nullable', 'date'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'failed', 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }

        $product = Product::with(['primaryImage', 'images'])->find($id);
        if (!$product) {
            return response()->json(['status' => 'failed', 'message' => 'Product not found'], 404);
        }

        $scheduleAt = null;
        if (!empty($data['scheduled_at'])) {
            $scheduleAt = Carbon::parse($data['scheduled_at']);
            if ($scheduleAt->lt(now()->addMinutes(self::MIN_SCHEDULE_MINUTES - 1)) || $scheduleAt->gt(now()->addDays(self::MAX_SCHEDULE_DAYS))) {
                return response()->json(['status' => 'failed', 'message' => 'Schedule a time at least ' . self::MIN_SCHEDULE_MINUTES . ' minutes from now and within ' . self::MAX_SCHEDULE_DAYS . ' days.'], 422);
            }
        }

        $all = $this->imageUrls($product);
        $photos = collect($data['image_indexes'] ?? [])->unique()->map(fn ($i) => $all[$i] ?? null)->filter()->values()->all();

        $user = $this->me($request);
        $pages = FacebookPage::where('user_id', $user->id)->whereIn('id', $data['page_ids'])->get();
        if ($pages->count() !== count(array_unique($data['page_ids']))) {
            return response()->json(['status' => 'failed', 'message' => 'One of these pages is not yours.'], 403);
        }

        $results = [];
        foreach ($pages as $page) {
            $results[] = $this->postToPage($page, $product, $user->id, $data['caption'], $photos, $scheduleAt);
        }
        $okCount = collect($results)->where('ok', true)->count();

        return response()->json([
            'status' => $okCount > 0 ? 'success' : 'failed',
            'message' => $okCount === count($results)
                ? ($scheduleAt ? 'Post scheduled' : 'Posted to Facebook')
                : "{$okCount} of " . count($results) . ' page(s) done',
            'data' => ['results' => $results],
        ], $okCount > 0 ? 200 : 502);
    }

    private function postToPage(FacebookPage $page, Product $product, int $userId, string $caption, array $photos, ?Carbon $scheduleAt): array
    {
        $base = ['page_id' => $page->id, 'page_name' => $page->page_name];
        if (!$page->isUsable()) {
            return $base + ['ok' => false, 'reason' => 'needs_reconnect', 'message' => 'This page needs to be connected again.'];
        }
        $record = ['facebook_page_id' => $page->id, 'product_id' => $product->id, 'user_id' => $userId, 'caption' => $caption,
            'image_count' => count($photos), 'scheduled_at' => $scheduleAt];

        try {
            $fbId = $this->graph->publishPost($page, $caption, $photos, $scheduleAt);
            $post = FacebookPost::create($record + ['fb_post_id' => $fbId, 'status' => $scheduleAt ? 'scheduled' : 'published']);

            return $base + ['ok' => true, 'status' => $post->status, 'fb_post_id' => $fbId, 'post_url' => $fbId ? 'https://www.facebook.com/' . $fbId : null];
        } catch (FacebookApiException $e) {
            if ($e->needsReconnect()) {
                $page->update(['status' => FacebookPage::STATUS_NEEDS_RECONNECT, 'last_error' => mb_substr($e->getMessage(), 0, 480), 'updated_at' => now()]);
            }
            FacebookPost::create($record + ['fb_post_id' => '', 'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 480)]);

            return $base + ['ok' => false, 'reason' => $e->needsReconnect() ? 'needs_reconnect' : 'facebook_error', 'message' => $e->getMessage()];
        }
    }

    /** The product's photos as public URLs: thumbnail first, then gallery in order, no duplicates. */
    private function imageUrls(Product $product): array
    {
        $base = rtrim((string) config('services.facebook.media_base_url'), '/');
        $toUrl = fn (?string $f) => !$f ? null : (preg_match('#^https?://#i', $f) ? $f : $base . '/' . ltrim($f, '/'));

        // product_images.image is an uploads.id (the column shares its name with the relation, so read it as an id).
        $ids = $product->images->sortBy('sort_order')->map(fn ($img) => (int) $img->getAttribute('image'))->filter()->values();
        $byId = Upload::whereIn('id', $ids)->pluck('file_name', 'id');
        $files = [$product->primaryImage?->file_name];
        foreach ($ids as $uploadId) {
            $files[] = $byId[$uploadId] ?? null;
        }

        return collect($files)->map($toUrl)->filter()->unique()->values()->all();
    }
}
