<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
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
            'errors' => $errors
        ], $code);
    }

    /**
     * POST /products/create
     * Creates product (optionally with images array)
     */
    public function createProduct(Request $request)
    {
        try {
            $validated = $request->validate([
                'shop_id' => ['nullable', 'integer', 'exists:shops,id'],
                'category_id' => ['nullable', 'integer', 'exists:categories,id'],
                'sub_category_id' => ['nullable', 'integer', 'exists:categories,id'],
                'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
                'related_id' => ['nullable', 'integer', 'exists:products,id'],

                'name' => ['nullable', 'string', 'max:255'],
                'slug' => ['nullable', 'string', 'max:255', 'unique:products,slug'],
                'sku' => ['nullable', 'string', 'max:255', 'unique:products,sku'],

                'short_description' => ['nullable', 'string'],
                'description' => ['nullable', 'string'],

                'thumbnail' => ['nullable', 'string', 'max:255'],

                'price' => ['nullable', 'numeric', 'min:0'],
                'sale_price' => ['nullable', 'numeric', 'min:0'],
                'cost_price' => ['nullable', 'numeric', 'min:0'],

                'stock' => ['nullable', 'integer', 'min:0'],
                'track_stock' => ['nullable', 'boolean'],
                'is_active' => ['nullable', 'boolean'],

                'status' => ['nullable', 'string', 'max:50'],
            ]);

            $product = Product::create([
                'shop_id' => $validated['shop_id'] ?? null,
                'category_id' => $validated['category_id'] ?? null,
                'sub_category_id' => $validated['sub_category_id'] ?? null,
                'brand_id' => $validated['brand_id'] ?? null,
                'related_id' => $validated['related_id'] ?? null,

                'name' => $validated['name'] ?? null,
                'slug' => $validated['slug'] ?? null,
                'sku' => $validated['sku'] ?? null,

                'short_description' => $validated['short_description'] ?? null,
                'description' => $validated['description'] ?? null,

                'thumbnail' => $validated['thumbnail'] ?? null,

                'price' => $validated['price'] ?? null,
                'sale_price' => $validated['sale_price'] ?? null,
                'cost_price' => $validated['cost_price'] ?? null,

                'stock' => $validated['stock'] ?? null,
                'track_stock' => array_key_exists('track_stock', $validated) ? (bool) $validated['track_stock'] : null,
                'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : null,

                'status' => $validated['status'] ?? null,
            ]);

            return $this->success('Product created successfully', $product, 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /products/images/upload/{productId}
     * Upload product images separately (store paths/urls, not multipart file yet)
     */
    public function productImageUpload(Request $request, $productId)
    {
        DB::beginTransaction();

        try {
            $product = Product::find($productId);
            if (!$product) {
                DB::rollBack();
                return $this->failed('Product not found', null, 404);
            }

            // 1) Validate multipart form-data files
            $validated = $request->validate([
                'images' => ['required', 'array', 'min:1'],

                // IMPORTANT: This must be a file, not a string
                'images.*.image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],

                'images.*.alt_text' => ['nullable', 'string', 'max:255'],
                'images.*.sort_order' => ['nullable', 'integer'],
                'images.*.is_primary' => ['nullable'], // handle manually because form-data can be "true","false","1","0"
                'images.*.status' => ['nullable', 'string', 'max:50'],
            ]);

            $created = [];

            foreach ($validated['images'] as $img) {

                // 2) Normalize is_primary from form-data reliably
                $isPrimary = false;
                if (array_key_exists('is_primary', $img)) {
                    $isPrimary = filter_var($img['is_primary'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    $isPrimary = ($isPrimary === null) ? false : $isPrimary;
                }

                // 3) If this one is primary, reset other primary flags
                if ($isPrimary) {
                    ProductImage::where('product_id', $product->id)->update(['is_primary' => false]);
                }

                // 4) Store file and save path
                // storage/app/public/products/{productId}/xxxx.webp
                $path = $img['image']->store("products/{$product->id}", 'public');

                $created[] = ProductImage::create([
                    'product_id' => $product->id,
                    'image' => $path, // store path in DB
                    'alt_text' => $img['alt_text'] ?? null,
                    'sort_order' => $img['sort_order'] ?? null,
                    'is_primary' => $isPrimary,
                    'status' => $img['status'] ?? 'active',
                ]);
            }

            DB::commit();

            $product->load(['images', 'primaryImage']);

            return $this->success('Product images uploaded successfully', [
                'product' => $product,
                'created_images' => $created,
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /products/list
     * Filters: shop_id, category_id, sub_category_id, brand_id, status, is_active, search
     */
    public function listProducts(Request $request)
    {
        try {
            $query = Product::query()->with(['primaryImage', 'images', 'category', 'subCategory', 'brand', 'productDiscount']);

            if ($request->filled('shop_id')) {
                $query->where('shop_id', $request->shop_id);
            }

            if ($request->filled('category_id')) {
                $query->where('category_id', $request->category_id);
            }

            if ($request->filled('sub_category_id')) {
                $query->where('sub_category_id', $request->sub_category_id);
            }

            if ($request->filled('brand_id')) {
                $query->where('brand_id', $request->brand_id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('is_active')) {
                $query->where('is_active', (int) $request->is_active);
            }

            if ($request->filled('search')) {
                $search = trim($request->search);
                // split into tokens so multi-word searches behave well
                $tokens = preg_split('/\s+/', $search);

                $query->where(function ($q) use ($tokens) {
                    foreach ($tokens as $token) {
                        $t = "%" . $token . "%";
                        $q->where(function ($qq) use ($t) {
                            $qq->where('name', 'like', $t)
                               
                                ->orWhere('slug', 'like', $t)
                                ->orWhereHas('category', function ($qc) use ($t) {
                                    $qc->where('name', 'like', $t);
                                })
                               
                                ->orWhereHas('brand', function ($qc) use ($t) {
                                    $qc->where('name', 'like', $t);
                                });
                        });
                    }
                });
            }

            $perPage = (int) $request->get('per_page', 20);
            $products = $query->latest()->paginate($perPage);

            return $this->success('Products fetched successfully', $products, 200,);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /products/details/{id}
     */
    public function getProductDetails($id)
    {
        try {
            $product = Product::with([
                'images',
                'primaryImage',
                'brand',
                'category',
                'subCategory',
                'shop',
                'related',
                'productAttributes.attribute',
                'productAttributes.value',
                'productDiscount',
            ])->find($id);

            if (!$product) {
                return $this->failed('Product not found', null, 404);
            }

            return $this->success('Product fetched successfully', $product);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /products/update/{id}
     */
    public function updateProduct(Request $request, $id)
    {
        try {
            $product = Product::find($id);

            if (!$product) {
                return $this->failed('Product not found', null, 404);
            }

            $validated = $request->validate([
                'shop_id' => ['nullable', 'integer', 'exists:shops,id'],
                'category_id' => ['nullable', 'integer', 'exists:categories,id'],
                'sub_category_id' => ['nullable', 'integer', 'exists:categories,id'],
                'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
                'related_id' => ['nullable', 'integer', 'exists:products,id'],

                'name' => ['nullable', 'string', 'max:255'],
                'slug' => ['nullable', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($product->id)],
                'sku' => ['nullable', 'string', 'max:255', Rule::unique('products', 'sku')->ignore($product->id)],

                'short_description' => ['nullable', 'string'],
                'description' => ['nullable', 'string'],

                'thumbnail' => ['nullable', 'string', 'max:255'],

                'price' => ['nullable', 'numeric', 'min:0'],
                'sale_price' => ['nullable', 'numeric', 'min:0'],
                'cost_price' => ['nullable', 'numeric', 'min:0'],

                'stock' => ['nullable', 'integer', 'min:0'],
                'track_stock' => ['nullable', 'boolean'],
                'is_active' => ['nullable', 'boolean'],

                'status' => ['nullable', 'string', 'max:50'],
            ]);

            $product->fill($validated);
            $product->save();

            $product->load(['images', 'primaryImage']);

            return $this->success('Product updated successfully', $product);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /products/delete/{id}
     */
    public function deleteProduct($id)
    {
        try {
            $product = Product::find($id);

            if (!$product) {
                return $this->failed('Product not found', null, 404);
            }

            // Optional: delete images too (if you want cascade behavior at app layer)
            ProductImage::where('product_id', $product->id)->delete();

            $product->delete();

            return $this->success('Product deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /products/images/add/{id}
     * Adds a new image to an existing product
     */
    public function addProductImage(Request $request, $id)
    {
        try {
            $product = Product::find($id);
            if (!$product) {
                return $this->failed('Product not found', null, 404);
            }

            $validated = $request->validate([
                'image' => ['nullable', 'string', 'max:255'],
                'alt_text' => ['nullable', 'string', 'max:255'],
                'sort_order' => ['nullable', 'integer'],
                'is_primary' => ['nullable', 'boolean'],
                'status' => ['nullable', 'string', 'max:50'],
            ]);

            // If setting as primary, unset others (optional but useful)
            if (array_key_exists('is_primary', $validated) && (bool) $validated['is_primary'] === true) {
                ProductImage::where('product_id', $product->id)->update(['is_primary' => false]);
            }

            $img = ProductImage::create([
                'product_id' => $product->id,
                'image' => $validated['image'] ?? null,
                'alt_text' => $validated['alt_text'] ?? null,
                'sort_order' => $validated['sort_order'] ?? null,
                'is_primary' => array_key_exists('is_primary', $validated) ? (bool) $validated['is_primary'] : null,
                'status' => $validated['status'] ?? null,
            ]);

            return $this->success('Product image added successfully', $img, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /products/images/delete/{imageId}
     */
    public function deleteProductImage($imageId)
    {
        try {
            $img = ProductImage::find($imageId);

            if (!$img) {
                return $this->failed('Product image not found', null, 404);
            }

            $img->delete();

            return $this->success('Product image deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
