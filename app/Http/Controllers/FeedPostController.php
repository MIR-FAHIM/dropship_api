<?php

namespace App\Http\Controllers;

use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\FeedPost;
use App\Models\FeedPostComment;
use App\Models\FeedPostReaction;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class FeedPostController extends Controller
{
    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }

    private function resolveUserId(Request $request): ?int
    {
        if ($request->filled('user_id')) {
            return (int) $request->user_id;
        }

        $user = $request->attributes->get('api_user') ?? $request->user();

        return $user ? (int) $user->id : null;
    }

    private function resolveUser(Request $request)
    {
        return $request->attributes->get('api_user') ?? $request->user();
    }

    /**
     * GET /feed-posts
     * List feed posts with reaction counts, current user reaction, and product info
     */
    public function index(Request $request)
    {
        try {
            $userId = $this->resolveUserId($request);

            $query = FeedPost::with([
                'admin:id,name,avatar,role,user_type',
                'product:id,name,sku,unit_price,admin_price,max_resell_price,photos,thumbnail_img',
                'product.primaryImage',
            ]);

            // Status filter (default to 'published' unless explicitly requested)
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            } else {
                $query->where('status', 'published');
            }

            // Product filter
            if ($request->filled('product_id')) {
                $query->where('product_id', (int) $request->product_id);
            }

            // Keyword Search
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                      ->orWhere('content', 'like', "%{$search}%");
                });
            }

            $perPage = (int) $request->get('per_page', 15);
            $posts = $query->latest()->paginate($perPage);

            // Fetch user's reactions for current page if authenticated
            $userReactions = [];
            if ($userId && $posts->count() > 0) {
                $postIds = $posts->pluck('id')->toArray();
                $userReactions = FeedPostReaction::where('user_id', $userId)
                    ->whereIn('feed_post_id', $postIds)
                    ->pluck('type', 'feed_post_id')
                    ->toArray();
            }

            // Transform posts with enriched reaction & product image data
            $posts->getCollection()->transform(function ($post) use ($userReactions) {
                $postData = $post->toArray();
                $postData['user_reaction'] = $userReactions[$post->id] ?? null;
                $postData['reaction_breakdown'] = $post->getReactionCounts();

                // Format full image URLs if relative paths stored
                if (!empty($postData['images']) && is_array($postData['images'])) {
                    $postData['images'] = array_map(function ($img) {
                        if (is_string($img) && !str_starts_with($img, 'http://') && !str_starts_with($img, 'https://')) {
                            return asset('storage/' . ltrim($img, '/'));
                        }
                        return $img;
                    }, $postData['images']);
                }

                return $postData;
            });

            return $this->success('Feed posts fetched successfully', $posts);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong fetching feed posts', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /feed-posts/{id}
     * Single feed post details
     */
    public function show(Request $request, $id)
    {
        try {
            $userId = $this->resolveUserId($request);

            $post = FeedPost::with([
                'admin:id,name,avatar,role,user_type',
                'product:id,name,sku,unit_price,admin_price,max_resell_price,photos,thumbnail_img',
                'product.primaryImage',
            ])->find($id);

            if (!$post) {
                return $this->failed('Feed post not found', null, 404);
            }

            $postData = $post->toArray();
            $postData['reaction_breakdown'] = $post->getReactionCounts();
            $postData['user_reaction'] = null;

            if ($userId) {
                $reaction = FeedPostReaction::where('feed_post_id', $id)
                    ->where('user_id', $userId)
                    ->value('type');
                $postData['user_reaction'] = $reaction;
            }

            return $this->success('Feed post fetched successfully', $postData);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /feed-posts
     * Create new feed post (Admin only)
     */
    public function store(Request $request)
    {
        try {
            $userId = $this->resolveUserId($request);
            if (!$userId) {
                return $this->failed('User is required to create a post', null, 401);
            }

            $validated = $request->validate([
                'product_id' => ['nullable', 'integer', 'exists:products,id'],
                'title' => ['nullable', 'string', 'max:255'],
                'content' => ['required', 'string'],
                'images' => ['nullable', 'array'],
                'images.*' => ['string'],
                'status' => ['nullable', 'string', 'in:published,draft,archived'],
                'metadata' => ['nullable', 'array'],
            ]);

            $post = FeedPost::create([
                'admin_id' => $userId,
                'product_id' => $validated['product_id'] ?? null,
                'title' => $validated['title'] ?? null,
                'content' => $validated['content'],
                'images' => $validated['images'] ?? [],
                'status' => $validated['status'] ?? 'published',
                'metadata' => $validated['metadata'] ?? null,
            ]);

            $post->load([
                'admin:id,name,avatar,role,user_type',
                'product:id,name,sku,unit_price,admin_price,max_resell_price',
            ]);

            return $this->success('Feed post created successfully', $post, 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Failed to create feed post', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /feed-posts/{id}
     * Update feed post
     */
    public function update(Request $request, $id)
    {
        try {
            $post = FeedPost::find($id);
            if (!$post) {
                return $this->failed('Feed post not found', null, 404);
            }

            $validated = $request->validate([
                'product_id' => ['nullable', 'integer', 'exists:products,id'],
                'title' => ['nullable', 'string', 'max:255'],
                'content' => ['sometimes', 'required', 'string'],
                'images' => ['nullable', 'array'],
                'status' => ['nullable', 'string', 'in:published,draft,archived'],
                'metadata' => ['nullable', 'array'],
            ]);

            $post->update($validated);

            $post->load([
                'admin:id,name,avatar,role,user_type',
                'product:id,name,sku,unit_price,admin_price,max_resell_price',
            ]);

            return $this->success('Feed post updated successfully', $post);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Failed to update feed post', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /feed-posts/{id}
     * Delete feed post
     */
    public function destroy(Request $request, $id)
    {
        try {
            $post = FeedPost::find($id);
            if (!$post) {
                return $this->failed('Feed post not found', null, 404);
            }

            $post->delete();

            return $this->success('Feed post deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Failed to delete feed post', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /feed-posts/{id}/react
     * Toggle or switch reaction (like, love, care, haha, wow, sad, angry)
     */
    public function react(Request $request, $id)
    {
        try {
            $userId = $this->resolveUserId($request);
            if (!$userId) {
                return $this->failed('Authentication required to react', null, 401);
            }

            $post = FeedPost::find($id);
            if (!$post) {
                return $this->failed('Feed post not found', null, 404);
            }

            $validated = $request->validate([
                'type' => ['required', 'string', 'in:like,love,care,haha,wow,sad,angry'],
            ]);

            $reactionType = $validated['type'];

            $existingReaction = FeedPostReaction::where('feed_post_id', $id)
                ->where('user_id', $userId)
                ->first();

            $userReaction = null;

            if ($existingReaction) {
                if ($existingReaction->type === $reactionType) {
                    // Clicked the same reaction again -> Un-react (toggle off)
                    $existingReaction->delete();
                    $post->decrement('likes_count');
                    $userReaction = null;
                } else {
                    // Clicked a different reaction -> Switch type
                    $existingReaction->update(['type' => $reactionType]);
                    $userReaction = $reactionType;
                }
            } else {
                // New reaction
                FeedPostReaction::create([
                    'feed_post_id' => $id,
                    'user_id' => $userId,
                    'type' => $reactionType,
                ]);
                $post->increment('likes_count');
                $userReaction = $reactionType;
            }

            $post->refresh();

            return $this->success('Reaction updated successfully', [
                'user_reaction' => $userReaction,
                'reactions_count' => $post->likes_count,
                'reaction_breakdown' => $post->getReactionCounts(),
            ]);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Failed to process reaction', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /feed-posts/{id}/comments
     * Get comments and nested replies
     */
    public function getComments(Request $request, $id)
    {
        try {
            $post = FeedPost::find($id);
            if (!$post) {
                return $this->failed('Feed post not found', null, 404);
            }

            $comments = FeedPostComment::with([
                'user:id,name,avatar,role,user_type',
                'replies' => function ($q) {
                    $q->with('user:id,name,avatar,role,user_type')->oldest();
                },
            ])
            ->where('feed_post_id', $id)
            ->whereNull('parent_id')
            ->where('status', 'active')
            ->latest()
            ->get();

            return $this->success('Comments fetched successfully', $comments);
        } catch (\Throwable $e) {
            return $this->failed('Failed to fetch comments', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /feed-posts/{id}/comments
     * Add comment or reply
     */
    public function addComment(Request $request, $id)
    {
        try {
            $userId = $this->resolveUserId($request);
            if (!$userId) {
                return $this->failed('Authentication required to comment', null, 401);
            }

            $post = FeedPost::find($id);
            if (!$post) {
                return $this->failed('Feed post not found', null, 404);
            }

            $validated = $request->validate([
                'comment' => ['required', 'string', 'max:2000'],
                'parent_id' => ['nullable', 'integer', 'exists:feed_post_comments,id'],
            ]);

            $comment = FeedPostComment::create([
                'feed_post_id' => $id,
                'user_id' => $userId,
                'parent_id' => $validated['parent_id'] ?? null,
                'comment' => $validated['comment'],
                'status' => 'active',
            ]);

            $post->increment('comments_count');

            $comment->load([
                'user:id,name,avatar,role,user_type',
                'replies.user:id,name,avatar,role,user_type',
            ]);

            return $this->success('Comment added successfully', $comment, 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Failed to post comment', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /feed-posts/comments/{commentId}
     * Delete comment or reply
     */
    public function deleteComment(Request $request, $commentId)
    {
        try {
            $userId = $this->resolveUserId($request);
            $user = $this->resolveUser($request);

            $comment = FeedPostComment::find($commentId);
            if (!$comment) {
                return $this->failed('Comment not found', null, 404);
            }

            // Check authorization: Must be author or admin
            $isAdmin = false;
            if ($user) {
                $roles = array_filter([
                    strtolower((string) ($user->user_type ?? '')),
                    strtolower((string) ($user->role ?? '')),
                ]);
                $isAdmin = (bool) array_intersect($roles, ['admin', 'superadmin', 'product manager']);
            }

            if ((int) $comment->user_id !== (int) $userId && !$isAdmin) {
                return $this->failed('You are not authorized to delete this comment', null, 403);
            }

            $postId = $comment->feed_post_id;
            $repliesCount = FeedPostComment::where('parent_id', $commentId)->count();
            $totalDeleted = 1 + $repliesCount;

            $comment->delete();

            // Decrement comments count on post
            $post = FeedPost::find($postId);
            if ($post) {
                $post->decrement('comments_count', min($post->comments_count, $totalDeleted));
            }

            return $this->success('Comment deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Failed to delete comment', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /feed-posts/{id}/share-to-page
     * Publish feed post directly to user's connected Facebook Page via Graph API
     */
    public function shareToFacebookPage(Request $request, $id)
    {
        try {
            $userId = $this->resolveUserId($request);
            if (!$userId) {
                return $this->failed('Authentication required to share', null, 401);
            }

            $post = FeedPost::with('product')->find($id);
            if (!$post) {
                return $this->failed('Feed post not found', null, 404);
            }

            $validated = $request->validate([
                'facebook_page_id' => ['required', 'integer', 'exists:facebook_pages,id'],
                'custom_caption' => ['nullable', 'string', 'max:2000'],
            ]);

            $page = FacebookPage::find($validated['facebook_page_id']);
            if (!$page) {
                return $this->failed('Facebook page not found', null, 404);
            }

            if ((int) $page->user_id !== (int) $userId) {
                return $this->failed('Facebook page does not belong to you', null, 403);
            }

            $token = $page->page_access_token;
            try {
                $decryptedToken = Crypt::decryptString($token);
                $token = $decryptedToken;
            } catch (\Throwable) {
                // If token was stored unencrypted
            }

            $caption = $validated['custom_caption'] ?? $post->content;
            $images = is_array($post->images) ? $post->images : [];
            $firstImageUrl = !empty($images[0]) ? $images[0] : null;

            $graphUrl = $firstImageUrl
                ? "https://graph.facebook.com/v19.0/{$page->page_id}/photos"
                : "https://graph.facebook.com/v19.0/{$page->page_id}/feed";

            $payload = $firstImageUrl
                ? ['url' => $firstImageUrl, 'caption' => $caption]
                : ['message' => $caption];

            $response = Http::asForm()->post($graphUrl, array_merge($payload, [
                'access_token' => $token,
            ]));

            $fbPostId = $response->json('post_id')
                ?? $response->json('id')
                ?? '';

            $fbPostRecord = FacebookPost::create([
                'feed_post_id' => $post->id,
                'facebook_page_id' => $validated['facebook_page_id'],
                'product_id' => $post->product_id ?? 0,
                'fb_post_id' => $fbPostId,
                'status' => $response->successful() ? 'published' : 'failed',
            ]);

            if ($response->successful()) {
                $post->increment('shares_count');
                return $this->success('Post shared to Facebook page successfully', [
                    'facebook_post' => $fbPostRecord,
                    'fb_post_id' => $fbPostId,
                ]);
            }

            return $this->failed('Failed to publish to Facebook page', [
                'facebook_response' => $response->json(),
                'record' => $fbPostRecord,
            ], 502);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Failed to share to Facebook page', ['error' => $e->getMessage()], 500);
        }
    }
}
