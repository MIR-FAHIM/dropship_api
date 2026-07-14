<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ResellerProductPage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ResellerProductPageController extends Controller
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

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = Str::slug($base) ?: 'product-page';
        $candidate = $slug;
        $counter = 1;

        while (
            ResellerProductPage::where('slug', $candidate)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = $slug . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    public function list(Request $request)
    {
        try {
            $query = ResellerProductPage::with(['reseller', 'product.images.image', 'product.primaryImage']);

            if ($request->filled('reseller_id')) {
                $query->where('reseller_id', $request->reseller_id);
            }

            if ($request->filled('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            if ($request->filled('published_status')) {
                $query->where('published_status', $request->published_status);
            }

            if ($request->filled('all') && (int) $request->get('all') === 1) {
                return $this->success('Reseller product pages fetched successfully', $query->latest()->get());
            }

            $perPage = (int) $request->get('per_page', 20);

            return $this->success('Reseller product pages fetched successfully', $query->latest()->paginate($perPage));
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function add(Request $request)
    {
        try {
            $validated = $request->validate([
                'reseller_id' => ['required', 'integer', 'exists:users,id'],
                'product_id' => [
                    'required',
                    'integer',
                    'exists:products,id',
                    Rule::unique('reseller_product_pages', 'product_id')
                        ->where(fn ($query) => $query->where('reseller_id', $request->reseller_id)),
                ],
                'slug' => ['nullable', 'string', 'max:255', 'unique:reseller_product_pages,slug'],
                'selling_price' => ['required', 'numeric', 'min:0'],
                'discount_price' => ['nullable', 'numeric', 'min:0'],
                'custom_title' => ['nullable', 'string', 'max:255'],
                'custom_description' => ['nullable', 'string'],
                'delivery_charge' => ['nullable', 'numeric', 'min:0'],
                'template_id' => ['nullable', 'integer'],
                'published_status' => ['nullable', 'string', 'max:50'],
            ]);

            if (empty($validated['slug'])) {
                $product = Product::find($validated['product_id']);
                $validated['slug'] = $this->uniqueSlug($validated['custom_title'] ?? $product?->name ?? 'product-page');
            }

            $page = ResellerProductPage::create($validated);

            return $this->success('Reseller product page created successfully', $page->load(['reseller', 'product']), 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function details($id)
    {
        try {
            $page = ResellerProductPage::with(['reseller', 'product.images', 'product.primaryImage'])->find($id);

            if (!$page) {
                return $this->failed('Reseller product page not found', null, 404);
            }

            return $this->success('Reseller product page fetched successfully', $page);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function getBySlug($slug)
    {
        try {
            $page = ResellerProductPage::with(['reseller', 'product.images', 'product.primaryImage'])
                ->where('slug', $slug)
                ->first();

            if (!$page) {
                return $this->failed('Reseller product page not found', null, 404);
            }

            return $this->success('Reseller product page fetched successfully', $page);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $page = ResellerProductPage::find($id);

            if (!$page) {
                return $this->failed('Reseller product page not found', null, 404);
            }

            $validated = $request->validate([
                'reseller_id' => ['sometimes', 'integer', 'exists:users,id'],
                'product_id' => ['sometimes', 'integer', 'exists:products,id'],
                'slug' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('reseller_product_pages', 'slug')->ignore($page->id),
                ],
                'selling_price' => ['sometimes', 'numeric', 'min:0'],
                'discount_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'custom_title' => ['sometimes', 'nullable', 'string', 'max:255'],
                'custom_description' => ['sometimes', 'nullable', 'string'],
                'delivery_charge' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'template_id' => ['sometimes', 'nullable', 'integer'],
                'published_status' => ['sometimes', 'nullable', 'string', 'max:50'],
            ]);

            $resellerId = $validated['reseller_id'] ?? $page->reseller_id;
            $productId = $validated['product_id'] ?? $page->product_id;
            $exists = ResellerProductPage::where('reseller_id', $resellerId)
                ->where('product_id', $productId)
                ->where('id', '!=', $page->id)
                ->exists();

            if ($exists) {
                return $this->failed('This reseller already has a page for this product', null, 422);
            }

            if (array_key_exists('slug', $validated) && empty($validated['slug'])) {
                $product = Product::find($productId);
                $validated['slug'] = $this->uniqueSlug($validated['custom_title'] ?? $product?->name ?? 'product-page', $page->id);
            }

            $page->update($validated);

            return $this->success('Reseller product page updated successfully', $page->load(['reseller', 'product']));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function remove($id)
    {
        try {
            $page = ResellerProductPage::find($id);

            if (!$page) {
                return $this->failed('Reseller product page not found', null, 404);
            }

            $page->delete();

            return $this->success('Reseller product page removed successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
