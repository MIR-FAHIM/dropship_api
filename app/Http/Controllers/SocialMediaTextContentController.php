<?php

namespace App\Http\Controllers;

use App\Models\SocialMediaTextContent;
use App\Models\Product;
use Illuminate\Http\Request;

class SocialMediaTextContentController extends Controller
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

    /**
     * GET /social-media-text-contents/list
     */
    public function list(Request $request)
    {
        try {
            $query = SocialMediaTextContent::with('product:id,name,sku,thumbnail_img');

            if ($request->filled('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            if ($request->filled('platform')) {
                $query->where('platform', $request->platform);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            }

            if ($request->filled('all') && (int) $request->get('all') === 1) {
                return $this->success('Social media text contents fetched', $query->latest()->get());
            }

            $perPage = (int) $request->get('per_page', 20);
            $items = $query->latest()->paginate($perPage);

            return $this->success('Social media text contents fetched', $items);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /social-media-text-contents/product/{productId}
     */
    public function getByProduct($productId)
    {
        try {
            $product = Product::find($productId);
            if (!$product) {
                return $this->failed('Product not found', null, 404);
            }

            $contents = SocialMediaTextContent::where('product_id', $productId)
                ->where('is_active', true)
                ->latest()
                ->get();

            return $this->success('Social media text contents for product fetched', [
                'product' => $product->only(['id', 'name', 'sku']),
                'text_contents' => $contents,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /social-media-text-contents/add
     */
    public function add(Request $request)
    {
        try {
            $validated = $request->validate([
                'product_id' => ['nullable', 'integer', 'exists:products,id'],
                'title' => ['nullable', 'string', 'max:255'],
                'platform' => ['nullable', 'string', 'max:100'],
                'content' => ['required', 'string'],
                'css_styles' => ['nullable', 'string'],
                'is_active' => ['sometimes', 'boolean'],
            ]);

            $content = SocialMediaTextContent::create($validated);

            return $this->success('Social media text content created successfully', $content, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /social-media-text-contents/details/{id}
     */
    public function details($id)
    {
        try {
            $content = SocialMediaTextContent::with('product:id,name,sku,thumbnail_img')->find($id);

            if (!$content) {
                return $this->failed('Social media text content not found', null, 404);
            }

            return $this->success('Social media text content fetched successfully', $content);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /social-media-text-contents/update/{id}
     * POST /social-media-text-contents/update/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $content = SocialMediaTextContent::find($id);

            if (!$content) {
                return $this->failed('Social media text content not found', null, 404);
            }

            $validated = $request->validate([
                'product_id' => ['nullable', 'integer', 'exists:products,id'],
                'title' => ['nullable', 'string', 'max:255'],
                'platform' => ['nullable', 'string', 'max:100'],
                'content' => ['sometimes', 'required', 'string'],
                'css_styles' => ['nullable', 'string'],
                'is_active' => ['sometimes', 'boolean'],
            ]);

            $content->update($validated);

            return $this->success('Social media text content updated successfully', $content->fresh('product:id,name,sku,thumbnail_img'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PATCH /social-media-text-contents/toggle-active/{id}
     */
    public function toggleActive($id)
    {
        try {
            $content = SocialMediaTextContent::find($id);

            if (!$content) {
                return $this->failed('Social media text content not found', null, 404);
            }

            $content->update(['is_active' => !$content->is_active]);

            return $this->success('Social media text content status updated', $content);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /social-media-text-contents/delete/{id}
     * DELETE /social-media-text-contents/remove/{id}
     */
    public function delete($id)
    {
        try {
            $content = SocialMediaTextContent::find($id);

            if (!$content) {
                return $this->failed('Social media text content not found', null, 404);
            }

            $content->delete();

            return $this->success('Social media text content deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
