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
                'name' => ['nullable', 'string', 'max:255'],
                'added_by' => ['nullable', 'string', 'max:255'],
                'user_id' => ['nullable', 'integer', 'exists:users,id'],
                'category_id' => ['nullable', 'integer', 'exists:categories,id'],
                'brand_id' => ['nullable', 'integer', 'exists:brands,id'],

                // photos may be an array of upload ids or a comma-separated string
                'photos' => ['nullable'],
                'photos.*' => ['integer', 'exists:uploads,id'],
                'thumbnail_img' => ['nullable', 'integer', 'exists:uploads,id'],

                'video_provider' => ['nullable', 'string', 'max:100'],
                'video_link' => ['nullable', 'string', 'max:255'],
                'tags' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string'],

                'unit_price' => ['nullable', 'numeric', 'min:0'],
                'purchase_price' => ['nullable', 'numeric', 'min:0'],

                'variant_product' => ['nullable', 'boolean'],
                'attributes' => ['nullable'],
                'choice_options' => ['nullable'],
                'colors' => ['nullable'],
                'variations' => ['nullable'],

                'todays_deal' => ['nullable', 'boolean'],
                'published' => ['nullable', 'boolean'],
                'approved' => ['nullable', 'boolean'],
                'stock_visibility_state' => ['nullable', 'string', 'max:50'],
                'cash_on_delivery' => ['nullable', 'boolean'],
                'featured' => ['nullable', 'boolean'],
                'seller_featured' => ['nullable', 'boolean'],

                'current_stock' => ['nullable', 'integer', 'min:0'],
                'unit' => ['nullable', 'string', 'max:50'],
                'weight' => ['nullable', 'numeric'],
                'min_qty' => ['nullable', 'integer'],
                'low_stock_quantity' => ['nullable', 'integer'],

                'discount' => ['nullable', 'numeric'],
                'discount_type' => ['nullable', 'string', 'max:20'],
                'discount_start_date' => ['nullable', 'date'],
                'discount_end_date' => ['nullable', 'date'],

                'tax' => ['nullable', 'numeric'],
                'tax_type' => ['nullable', 'string', 'max:20'],

                'shipping_type' => ['nullable', 'string', 'max:50'],
                'shipping_cost' => ['nullable', 'numeric'],

                'is_quantity_multiplied' => ['nullable', 'boolean'],
                'est_shipping_days' => ['nullable', 'integer'],

                'meta_title' => ['nullable', 'string', 'max:255'],
                'meta_description' => ['nullable', 'string', 'max:1000'],
                'meta_img' => ['nullable', 'string', 'max:255'],

                'slug' => ['nullable', 'string', 'max:255', 'unique:products,slug'],
                'refundable' => ['nullable', 'boolean'],
                'earn_point' => ['nullable', 'integer'],
                'rating' => ['nullable', 'numeric'],
                'barcode' => ['nullable', 'string', 'max:255'],
                'digital' => ['nullable', 'boolean'],
                'auction_product' => ['nullable', 'boolean'],
                'file_name' => ['nullable', 'string', 'max:255'],
                'file_path' => ['nullable', 'string', 'max:255'],
                'external_link' => ['nullable', 'string', 'max:255'],
                'external_link_btn' => ['nullable', 'string', 'max:255'],
                'wholesale_product' => ['nullable', 'boolean'],
                'frequently_brought_selection_type' => ['nullable', 'string', 'max:50'],
            ]);

            // Normalize photos: accept array of ids or comma string
            $photos = null;
            if (array_key_exists('photos', $validated)) {
                if (is_array($validated['photos'])) {
                    $photos = implode(',', $validated['photos']);
                } else {
                    $photos = (string) $validated['photos'];
                }
            }

            $productData = [
                'name' => $validated['name'] ?? null,
                'added_by' => $validated['added_by'] ?? null,
                'user_id' => $validated['user_id'] ?? null,
                'category_id' => $validated['category_id'] ?? null,
                'brand_id' => $validated['brand_id'] ?? null,
                'photos' => $photos,
                'thumbnail_img' => $validated['thumbnail_img'] ?? null,
                'video_provider' => $validated['video_provider'] ?? null,
                'video_link' => $validated['video_link'] ?? null,
                'tags' => $validated['tags'] ?? null,
                'description' => $validated['description'] ?? null,
                'unit_price' => $validated['unit_price'] ?? null,
                'purchase_price' => $validated['purchase_price'] ?? null,
                'variant_product' => array_key_exists('variant_product', $validated) ? (bool) $validated['variant_product'] : null,
                'attributes' => $validated['attributes'] ?? null,
                'choice_options' => $validated['choice_options'] ?? null,
                'colors' => $validated['colors'] ?? null,
                'variations' => $validated['variations'] ?? null,
                'todays_deal' => array_key_exists('todays_deal', $validated) ? (bool) $validated['todays_deal'] : null,
                'published' => array_key_exists('published', $validated) ? (bool) $validated['published'] : null,
                'approved' => array_key_exists('approved', $validated) ? (bool) $validated['approved'] : null,
                'stock_visibility_state' => $validated['stock_visibility_state'] ?? null,
                'cash_on_delivery' => array_key_exists('cash_on_delivery', $validated) ? (bool) $validated['cash_on_delivery'] : null,
                'featured' => array_key_exists('featured', $validated) ? (bool) $validated['featured'] : null,
                'seller_featured' => array_key_exists('seller_featured', $validated) ? (bool) $validated['seller_featured'] : null,
                'current_stock' => $validated['current_stock'] ?? null,
                'unit' => $validated['unit'] ?? null,
                'weight' => $validated['weight'] ?? null,
                'min_qty' => $validated['min_qty'] ?? null,
                'low_stock_quantity' => $validated['low_stock_quantity'] ?? null,
                'discount' => $validated['discount'] ?? null,
                'discount_type' => $validated['discount_type'] ?? null,
                'discount_start_date' => $validated['discount_start_date'] ?? null,
                'discount_end_date' => $validated['discount_end_date'] ?? null,
                'tax' => $validated['tax'] ?? null,
                'tax_type' => $validated['tax_type'] ?? null,
                'shipping_type' => $validated['shipping_type'] ?? null,
                'shipping_cost' => $validated['shipping_cost'] ?? null,
                'is_quantity_multiplied' => array_key_exists('is_quantity_multiplied', $validated) ? (bool) $validated['is_quantity_multiplied'] : null,
                'est_shipping_days' => $validated['est_shipping_days'] ?? null,
                'num_of_sale' => $validated['num_of_sale'] ?? null,
                'meta_title' => $validated['meta_title'] ?? null,
                'meta_description' => $validated['meta_description'] ?? null,
                'meta_img' => $validated['meta_img'] ?? null,
                'pdf' => $validated['pdf'] ?? null,
                'slug' => $validated['slug'] ?? null,
                'refundable' => array_key_exists('refundable', $validated) ? (bool) $validated['refundable'] : null,
                'earn_point' => $validated['earn_point'] ?? null,
                'rating' => $validated['rating'] ?? null,
                'barcode' => $validated['barcode'] ?? null,
                'digital' => array_key_exists('digital', $validated) ? (bool) $validated['digital'] : null,
                'auction_product' => array_key_exists('auction_product', $validated) ? (bool) $validated['auction_product'] : null,
                'file_name' => $validated['file_name'] ?? null,
                'file_path' => $validated['file_path'] ?? null,
                'external_link' => $validated['external_link'] ?? null,
                'external_link_btn' => $validated['external_link_btn'] ?? null,
                'wholesale_product' => array_key_exists('wholesale_product', $validated) ? (bool) $validated['wholesale_product'] : null,
                'frequently_brought_selection_type' => $validated['frequently_brought_selection_type'] ?? null,
            ];

            $product = Product::create($productData);

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
                'name' => ['nullable', 'string', 'max:255'],
                'added_by' => ['nullable', 'string', 'max:255'],
                'user_id' => ['nullable', 'integer', 'exists:users,id'],
                'category_id' => ['nullable', 'integer', 'exists:categories,id'],
                'brand_id' => ['nullable', 'integer', 'exists:brands,id'],

            
                'thumbnail_img' => ['nullable', 'integer', 'exists:uploads,id'],

                'video_provider' => ['nullable', 'string', 'max:100'],
                'video_link' => ['nullable', 'string', 'max:255'],
                'tags' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string'],

                'unit_price' => ['nullable', 'numeric', 'min:0'],
                'purchase_price' => ['nullable', 'numeric', 'min:0'],

                'variant_product' => ['nullable', 'boolean'],
                'attributes' => ['nullable'],
                'choice_options' => ['nullable'],
                'colors' => ['nullable'],
                'variations' => ['nullable'],

                'todays_deal' => ['nullable', 'boolean'],
                'published' => ['nullable', 'boolean'],
                'approved' => ['nullable', 'boolean'],
                'stock_visibility_state' => ['nullable', 'string', 'max:50'],
                'cash_on_delivery' => ['nullable', 'boolean'],
                'featured' => ['nullable', 'boolean'],
                'seller_featured' => ['nullable', 'boolean'],

                'current_stock' => ['nullable', 'integer', 'min:0'],
                'unit' => ['nullable', 'string', 'max:50'],
                'weight' => ['nullable', 'numeric'],
                'min_qty' => ['nullable', 'integer'],
                'low_stock_quantity' => ['nullable', 'integer'],

                'discount' => ['nullable', 'numeric'],
                'discount_type' => ['nullable', 'string', 'max:20'],
                'discount_start_date' => ['nullable', 'date'],
                'discount_end_date' => ['nullable', 'date'],

                'tax' => ['nullable', 'numeric'],
                'tax_type' => ['nullable', 'string', 'max:20'],

                'shipping_type' => ['nullable', 'string', 'max:50'],
                'shipping_cost' => ['nullable', 'numeric'],

                'is_quantity_multiplied' => ['nullable', 'boolean'],
                'est_shipping_days' => ['nullable', 'integer'],

                'meta_title' => ['nullable', 'string', 'max:255'],
                'meta_description' => ['nullable', 'string', 'max:1000'],
                'meta_img' => ['nullable', 'string', 'max:255'],

                'slug' => ['nullable', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($product->id)],
                'refundable' => ['nullable', 'boolean'],
                'earn_point' => ['nullable', 'integer'],
                'rating' => ['nullable', 'numeric'],
                'barcode' => ['nullable', 'string', 'max:255'],
                'digital' => ['nullable', 'boolean'],
                'auction_product' => ['nullable', 'boolean'],
                'file_name' => ['nullable', 'string', 'max:255'],
                'file_path' => ['nullable', 'string', 'max:255'],
                'external_link' => ['nullable', 'string', 'max:255'],
                'external_link_btn' => ['nullable', 'string', 'max:255'],
                'wholesale_product' => ['nullable', 'boolean'],
                'frequently_brought_selection_type' => ['nullable', 'string', 'max:50'],
            ]);

            

            // Normalize boolean flags explicitly when present
            foreach ([
                'variant_product',
                'todays_deal',
                'published',
                'approved',
                'cash_on_delivery',
                'featured',
                'seller_featured',
                'is_quantity_multiplied',
                'refundable',
                'digital',
                'auction_product',
                'wholesale_product',
            ] as $flag) {
                if (array_key_exists($flag, $validated)) {
                    $validated[$flag] = (bool) $validated[$flag];
                }
            }

            $product->fill($validated);
            $product->save();

           

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
