<?php

namespace App\Http\Controllers;

use App\Models\FacebookAccount;
use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class FacebookPostController extends Controller
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

        $user = $request->attributes->get('api_user');

        return $user ? (int) $user->id : null;
    }

    /**
     * POST /facebook/accounts/add
     */
    public function addAccount(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => ['nullable', 'integer', 'exists:users,id'],
                'fb_user_id' => ['required', 'string', 'max:255'],
                'fb_name' => ['required', 'string', 'max:255'],
            ]);

            $userId = $this->resolveUserId($request) ?? ($validated['user_id'] ?? null);

            if (!$userId) {
                return $this->failed('User is required', null, 422);
            }

            $account = FacebookAccount::create([
                'user_id' => $userId,
                'fb_user_id' => $validated['fb_user_id'],
                'fb_name' => $validated['fb_name'],
            ]);

            return $this->success('Facebook account added', $account, 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /facebook/accounts
     */
    public function getAccounts(Request $request)
    {
        try {
            $query = FacebookAccount::query();

            $userId = $this->resolveUserId($request);
            if ($userId) {
                $query->where('user_id', $userId);
            } elseif ($request->filled('user_id')) {
                $query->where('user_id', (int) $request->user_id);
            }

            $perPage = (int) $request->get('per_page', 20);
            $accounts = $query->latest()->paginate($perPage);

            return $this->success('Facebook accounts fetched', $accounts);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /facebook/pages/add
     */
    public function addPage(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => ['nullable', 'integer', 'exists:users,id'],
                'facebook_account_id' => ['required', 'integer', 'exists:facebook_accounts,id'],
                'page_id' => ['required', 'string', 'max:255'],
                'page_name' => ['required', 'string', 'max:255'],
                'page_access_token' => ['required', 'string'],
                'category' => ['required', 'string', 'max:255'],
            ]);

            $userId = $this->resolveUserId($request) ?? ($validated['user_id'] ?? null);

            if (!$userId) {
                return $this->failed('User is required', null, 422);
            }

            $account = FacebookAccount::find($validated['facebook_account_id']);
            if (!$account) {
                return $this->failed('Facebook account not found', null, 404);
            }

            if ((int) $account->user_id !== (int) $userId) {
                return $this->failed('Facebook account does not belong to user', null, 403);
            }

            $page = FacebookPage::create([
                'user_id' => $userId,
                'facebook_account_id' => $validated['facebook_account_id'],
                'page_id' => $validated['page_id'],
                'page_name' => $validated['page_name'],
                'page_access_token' => Crypt::encryptString($validated['page_access_token']),
                'category' => $validated['category'],
            ]);

            return $this->success('Facebook page added', $page, 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /facebook/pages
     */
    public function getPages(Request $request)
    {
        try {
            $query = FacebookPage::query();

            $userId = $this->resolveUserId($request);
            if ($userId) {
                $query->where('user_id', $userId);
            } elseif ($request->filled('user_id')) {
                $query->where('user_id', (int) $request->user_id);
            }

            if ($request->filled('facebook_account_id')) {
                $query->where('facebook_account_id', (int) $request->facebook_account_id);
            }

            $perPage = (int) $request->get('per_page', 20);
            $pages = $query->latest()->paginate($perPage);



            return $this->success('Facebook pages fetched', $pages);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /facebook/posts/publish
     */
    public function publishContent(Request $request)
    {
        try {
            $validated = $request->validate([
                'facebook_page_id' => ['required', 'integer', 'exists:facebook_pages,id'],
                'product_id' => ['required', 'integer', 'exists:products,id'],
                'fb_post_id' => ['nullable', 'string', 'max:255'],
                'status' => ['nullable', 'string', 'max:100'],
                'caption' => ['nullable', 'string', 'max:2000'],
                'image_url' => ['nullable', 'url'],
            ]);

            $page = FacebookPage::find($validated['facebook_page_id']);
            if (!$page) {
                return $this->failed('Facebook page not found', null, 404);
            }

            $userId = $this->resolveUserId($request);
            if ($userId && (int) $page->user_id !== (int) $userId) {
                return $this->failed('Facebook page does not belong to user', null, 403);
            }

            $product = Product::find($validated['product_id']);
            if (!$product) {
                return $this->failed('Product not found', null, 404);
            }

           
                $token = $page->page_access_token;
        ;
         

            $caption = $validated['caption'] ?? $product->name ?? 'New product';
            $imageUrl = $validated['image_url'] ?? $product->thumbnail_url ?? null;

            if (!$imageUrl) {
                $photos = $product->photos_array ?? [];
                if (!empty($photos)) {
                    $first = $photos[0];
                    $imageUrl = str_starts_with($first, 'http') ? $first : asset('storage/' . $first);
                }
            }

            if ($imageUrl && !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $imageUrl = null;
            }

            $graphUrl = $imageUrl
                ? "https://graph.facebook.com/v19.0/{$page->page_id}/photos"
                : "https://graph.facebook.com/v19.0/{$page->page_id}/feed";

            $payload = $imageUrl
                ? ['url' => $imageUrl, 'caption' => $caption]
                : ['message' => $caption];

            $response = Http::asForm()->post($graphUrl, array_merge($payload, [
                'access_token' => $token,
            ]));
            $responseBody = $response->json();
            $fbPostId = $response->json('post_id')
                ?? $response->json('id')
                ?? '';

            $post = FacebookPost::create([
                'facebook_page_id' => $validated['facebook_page_id'],
                'product_id' => $validated['product_id'],
                'fb_post_id' => $fbPostId,
                'status' => $response->successful() ? 'published' : 'failed',
            ]);

            if (!$response->successful()) {
                return $this->failed('Failed to publish content', [
                    'facebook_response' => $response->json(),
                    'post' => $post,
                    'payload' => [
                        'graph_url' => $graphUrl,
                        'caption' => $caption,
                        'image_url' => $imageUrl,
                    ],
                ], 502);
            }
            return $this->success('Content published', $post, 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /facebook/posts
     */
    public function getContents(Request $request)
    {
        try {
            $query = FacebookPost::with(['facebookPage', 'product']);

            if ($request->filled('facebook_page_id')) {
                $query->where('facebook_page_id', (int) $request->facebook_page_id);
            }

            if ($request->filled('product_id')) {
                $query->where('product_id', (int) $request->product_id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $userId = $this->resolveUserId($request);
            if ($userId) {
                $query->whereHas('facebookPage', function ($q) use ($userId) {
                    $q->where('user_id', $userId);
                });
            }

            $perPage = (int) $request->get('per_page', 20);
            $posts = $query->latest()->paginate($perPage);

            return $this->success('Contents fetched', $posts);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /facebook/posts/republish/{id}
     */
    public function rePublish(Request $request, $id)
    {
        try {
            $post = FacebookPost::find($id);
            if (!$post) {
                return $this->failed('Content not found', null, 404);
            }

            $userId = $this->resolveUserId($request);
            if ($userId) {
                $page = FacebookPage::find($post->facebook_page_id);
                if ($page && (int) $page->user_id !== (int) $userId) {
                    return $this->failed('Content does not belong to user', null, 403);
                }
            }

            $validated = $request->validate([
                'fb_post_id' => ['nullable', 'string', 'max:255'],
                'status' => ['nullable', 'string', 'max:100'],
            ]);

            $post->update([
                'fb_post_id' => $validated['fb_post_id'] ?? $post->fb_post_id,
                'status' => $validated['status'] ?? 'republished',
            ]);

            return $this->success('Content republished', $post);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /facebook/posts/delete/{id}
     */
    public function deleteContent(Request $request, $id)
    {
        try {
            $post = FacebookPost::find($id);
            if (!$post) {
                return $this->failed('Content not found', null, 404);
            }

            $userId = $this->resolveUserId($request);
            if ($userId) {
                $page = FacebookPage::find($post->facebook_page_id);
                if ($page && (int) $page->user_id !== (int) $userId) {
                    return $this->failed('Content does not belong to user', null, 403);
                }
            }

            $post->delete();

            return $this->success('Content deleted');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
