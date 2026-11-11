<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\PartnerStore;
use App\Models\Product;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PartnerApiController extends Controller
{
    /**
     * Standard JSON success response
     */
    private function success(string $message, mixed $data = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    /**
     * Standard JSON failed response
     */
    private function failed(string $message, mixed $errors = null, int $code = 400): JsonResponse
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }

    /**
     * Resolve image URL from Upload instance, ID, or raw path
     */
    private function resolveImageUrl(mixed $uploadOrId): ?string
    {
        if (empty($uploadOrId)) {
            return null;
        }

        if ($uploadOrId instanceof Upload) {
            return $uploadOrId->url;
        }

        if (is_numeric($uploadOrId)) {
            $upload = Upload::find($uploadOrId);
            return $upload?->url;
        }

        if (is_string($uploadOrId)) {
            if (str_starts_with($uploadOrId, 'http://') || str_starts_with($uploadOrId, 'https://')) {
                return $uploadOrId;
            }
            return asset('storage/' . ltrim($uploadOrId, '/'));
        }

        return null;
    }

    /**
     * Format product object for partner API consumer
     */
    private function formatProduct(Product $product): array
    {
        $primaryImageUrl = null;
        if ($product->relationLoaded('primaryImage') && ($product->primaryImage instanceof Upload)) {
            $primaryImageUrl = $product->primaryImage->url;
        } else {
            $primaryImageUrl = $this->resolveImageUrl($product->thumbnail_img ?: ($product->primaryImage ?? null));
        }

        $galleryImages = [];
        if ($product->relationLoaded('images') && $product->images->isNotEmpty()) {
            foreach ($product->images as $img) {
                $resolved = ($img->image instanceof Upload) 
                    ? $img->image->url 
                    : $this->resolveImageUrl($img->image);

                if ($resolved) {
                    $galleryImages[] = $resolved;
                }
            }
        } elseif (!empty($product->photos)) {
            $photoIds = explode(',', $product->photos);
            foreach ($photoIds as $pId) {
                $resolved = $this->resolveImageUrl(trim($pId));
                if ($resolved) {
                    $galleryImages[] = $resolved;
                }
            }
        }

        if ($primaryImageUrl && !in_array($primaryImageUrl, $galleryImages, true)) {
            array_unshift($galleryImages, $primaryImageUrl);
        }

        $wholesalePrice = (float) ($product->admin_price > 0 ? $product->admin_price : $product->unit_price);
        $maxResellPrice = (float) ($product->max_resell_price > 0 ? $product->max_resell_price : ($wholesalePrice * 1.5));
        $suggestedPrice = (float) ($product->unit_price > 0 ? $product->unit_price : ($wholesalePrice * 1.3));

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'admin_price' => $wholesalePrice,         // Wholesale price dropshipper pays to ResellerBrain
            'suggested_price' => $suggestedPrice,     // Recommended retail price
            'max_resell_price' => $maxResellPrice,    // Maximum ceiling price
            'current_stock' => (int) $product->current_stock,
            'in_stock' => (int) $product->current_stock > 0,
            'min_qty' => (int) ($product->min_qty ?: 1),
            'weight' => (float) $product->weight,
            'primary_image_url' => $primaryImageUrl,
            'gallery_images' => array_values(array_unique($galleryImages)),
            'description' => $product->description,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug ?? null,
            ] : null,
            'attributes' => $product->relationLoaded('productAttributes') 
                ? $product->productAttributes->map(fn($attr) => [
                    'id' => $attr->id,
                    'attribute_id' => $attr->attribute_id,
                    'values' => $attr->values,
                    'price' => (float) $attr->price,
                ]) 
                : [],
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }

    // ==========================================
    // PARTNER EXTERNAL API (Authenticated via PartnerApiAuth)
    // ==========================================

    /**
     * GET /api/partner/v1/auth/verify
     * Verify credentials and return store & dropshipper account status
     */
    public function verifyAuth(Request $request): JsonResponse
    {
        /** @var PartnerStore $store */
        $store = $request->get('partner_store');
        /** @var User $user */
        $user = $request->get('partner_user');

        return $this->success('Partner authenticated successfully', [
            'store' => [
                'id' => $store->id,
                'store_name' => $store->store_name,
                'store_domain' => $store->store_domain,
                'store_url' => $store->store_url,
                'is_active' => $store->is_active,
                'webhook_url' => $store->webhook_url,
                'last_sync_at' => $store->last_sync_at?->toIso8601String(),
            ],
            'dropshipper' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'balance' => (float) ($user->balance ?? 0),
                'banned' => (bool) ($user->banned ?? false),
            ],
        ]);
    }

    /**
     * GET /api/partner/v1/categories
     * List active product categories
     */
    public function categories(Request $request): JsonResponse
    {
        $categories = Category::query()
            ->select('id', 'name', 'slug', 'banner', 'icon')
            ->orderBy('name')
            ->get()
            ->map(function ($cat) {
                return [
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'slug' => $cat->slug ?? Str::slug($cat->name),
                    'icon' => $this->resolveImageUrl($cat->icon),
                    'banner' => $this->resolveImageUrl($cat->banner),
                ];
            });

        return $this->success('Categories fetched successfully', $categories);
    }

    /**
     * GET /api/partner/v1/products
     * Paginated product catalog with wholesale prices
     */
    public function products(Request $request): JsonResponse
    {
        try {
            $query = Product::query()
                ->with(['primaryImage', 'images.image', 'category', 'productAttributes'])
                ->where('published', 1)
                ->where('approved', 1);

            // Filter in stock only (default true)
            if ($request->boolean('in_stock_only', true)) {
                $query->where('current_stock', '>', 0);
            }

            // Filter by category
            if ($request->filled('category_id')) {
                $query->where('category_id', $request->input('category_id'));
            }

            // Delta sync: fetch only products updated after a certain timestamp
            if ($request->filled('updated_after')) {
                $query->where('updated_at', '>=', $request->input('updated_after'));
            }

            // Search by keyword
            if ($request->filled('search')) {
                $term = '%' . trim($request->input('search')) . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                      ->orWhere('sku', 'like', $term);
                });
            }

            $perPage = min((int) $request->input('per_page', 24), 100);
            $paginator = $query->latest('updated_at')->paginate($perPage);

            $formatted = collect($paginator->items())->map(fn($p) => $this->formatProduct($p));

            return $this->success('Products retrieved successfully', [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'products' => $formatted,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Failed to retrieve products: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * GET /api/partner/v1/products/{id}
     * Real-time single product stock & wholesale price check
     */
    public function productDetails(Request $request, string|int $id): JsonResponse
    {
        $product = Product::with(['primaryImage', 'images.image', 'category', 'productAttributes'])
            ->where('published', 1)
            ->where('approved', 1)
            ->where(function ($q) use ($id) {
                if (is_numeric($id)) {
                    $q->where('id', $id)->orWhere('sku', (string) $id);
                } else {
                    $q->where('sku', $id)->orWhere('slug', $id);
                }
            })
            ->first();

        if (!$product) {
            return $this->failed('Product not found or currently unavailable.', null, 404);
        }

        return $this->success('Product details retrieved successfully', $this->formatProduct($product));
    }

    /**
     * POST /api/partner/v1/orders/create
     * External website places an order into ResellerBrain for white-label dropship fulfillment
     */
    public function createOrder(Request $request): JsonResponse
    {
        /** @var PartnerStore $store */
        $store = $request->get('partner_store');
        /** @var User $dropshipper */
        $dropshipper = $request->get('partner_user');

        $validator = Validator::make($request->all(), [
            'external_order_id' => 'required|string|max:100',
            'customer_name' => 'required|string|max:191',
            'customer_phone' => 'required|string|max:20',
            'shipping_address' => 'required|string|max:500',
            'district' => 'nullable|string|max:100',
            'zone' => 'nullable|string|max:100',
            'area' => 'nullable|string|max:100',
            'shipping_fee' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.selling_price' => 'required|numeric|min:0',
            'items.*.attribute_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return $this->failed('Validation error', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        $externalOrderId = $validated['external_order_id'];

        // Prevent duplicate processing of the same external order ID from this store
        $existingOrder = Order::where('user_id', $dropshipper->id)
            ->where('note', 'like', "%[ExtOrder:{$externalOrderId}]%")
            ->first();

        if ($existingOrder) {
            return $this->failed('Order with external_order_id [' . $externalOrderId . '] was already received.', [
                'order_number' => $existingOrder->order_number,
                'status' => $existingOrder->orderStatus?->name ?? 'Pending',
            ], 409);
        }

        try {
            DB::beginTransaction();

            // Fetch products with exclusive lock for stock safety
            $productIds = collect($validated['items'])->pluck('product_id')->unique()->all();
            $products = Product::whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');

            $itemsData = [];
            $sellingSubtotal = 0.0;
            $resellerProfit = 0.0;
            $primaryVendorId = null;

            foreach ($validated['items'] as $item) {
                $productId = $item['product_id'];
                $qty = (int) $item['quantity'];
                $sellingPrice = (float) $item['selling_price'];
                $attributeId = $item['attribute_id'] ?? null;

                $product = $products->get($productId);
                if (!$product) {
                    DB::rollBack();
                    return $this->failed("Product ID {$productId} not found", null, 404);
                }

                // Check stock
                if ($product->current_stock < $qty) {
                    DB::rollBack();
                    return $this->failed("Insufficient stock for product '{$product->name}'. Available: {$product->current_stock}, Requested: {$qty}", null, 422);
                }

                // Wholesale base cost
                $adminPrice = (float) ($product->admin_price > 0 ? $product->admin_price : $product->unit_price);

                // Selling price protection
                if ($sellingPrice < $adminPrice) {
                    DB::rollBack();
                    return $this->failed("Selling price ({$sellingPrice} ৳) for '{$product->name}' cannot be lower than ResellerBrain wholesale cost ({$adminPrice} ৳).", null, 422);
                }

                if (!$primaryVendorId) {
                    $primaryVendorId = $product->vendor_id;
                }

                $lineTotal = round($sellingPrice * $qty, 2);
                $lineProfit = round(($sellingPrice - $adminPrice) * $qty, 2);

                $sellingSubtotal += $lineTotal;
                $resellerProfit += $lineProfit;

                $itemsData[] = [
                    'product' => $product,
                    'qty' => $qty,
                    'selling_price' => $sellingPrice,
                    'admin_price' => $adminPrice,
                    'line_total' => $lineTotal,
                    'line_profit' => $lineProfit,
                    'attribute_id' => $attributeId,
                ];
            }

            $shippingFee = (float) $validated['shipping_fee'];
            $totalCollectableCod = round($sellingSubtotal + $shippingFee, 2);

            // Generate clean human-readable order number
            $orderNumber = 'ORD-P' . $store->id . '-' . date('Ymd') . '-' . strtoupper(Str::random(5));

            // Formulate white-label store note
            $storeAnnotation = "[Store:{$store->store_name}][ExtOrder:{$externalOrderId}]";
            $customNote = !empty($validated['note']) ? ' | ' . trim($validated['note']) : '';
            $finalNote = $storeAnnotation . $customNote;

            $order = Order::create([
                'user_id' => $dropshipper->id,
                'order_number' => $orderNumber,
                'status' => 1, // Pending
                'payment_status' => 'unpaid',

                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
                'shipping_address' => $validated['shipping_address'],

                'district' => $validated['district'] ?? null,
                'zone' => $validated['zone'] ?? null,
                'area' => $validated['area'] ?? null,

                'subtotal' => round($sellingSubtotal, 2),
                'reseller_price' => round($sellingSubtotal, 2),
                'shipping_fee' => $shippingFee,
                'discount' => 0.0,
                'total' => $totalCollectableCod,
                'reseller_profit' => round($resellerProfit, 2),
                'vendor_id' => $primaryVendorId,

                'note' => $finalNote,
            ]);

            // Create OrderStatusHistory
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status_id' => 1,
                'note' => "Order ingested via Partner API from Store: {$store->store_name} (Ext ID: {$externalOrderId})",
                'changed_by' => $dropshipper->id,
                'source' => \App\Models\OrderStatusHistory::SOURCE_SYSTEM,
            ]);

            // Create line items & decrement stock
            foreach ($itemsData as $item) {
                $p = $item['product'];

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $p->id,
                    'shop_id' => $p->vendor_id,
                    'attribute_id' => $item['attribute_id'],
                    'product_name' => $p->name,
                    'sku' => $p->sku,
                    'unit_price' => $item['selling_price'],
                    'admin_price' => $item['admin_price'],
                    'reseller_price' => $item['selling_price'],
                    'qty' => $item['qty'],
                    'line_total' => $item['line_total'],
                    'line_total_reseller_profit' => $item['line_profit'],
                    'status' => 1,
                    'note' => "Partner Ext-Order: {$externalOrderId}",
                ]);

                // Decrement stock
                $p->decrement('current_stock', $item['qty']);
            }

            DB::commit();

            return $this->success('Order received and successfully queued for fulfillment in ResellerBrain', [
                'order_number' => $order->order_number,
                'external_order_id' => $externalOrderId,
                'status' => 'Pending',
                'customer_name' => $order->customer_name,
                'total_cod' => $order->total,
                'reseller_profit' => $order->reseller_profit,
                'items_count' => count($itemsData),
                'created_at' => $order->created_at->toIso8601String(),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->failed('Failed to process partner order: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * GET /api/partner/v1/orders/{orderNumber}/status
     * Real-time fulfillment & tracking status for an order
     */
    public function orderStatus(Request $request, string $orderNumber): JsonResponse
    {
        /** @var PartnerStore $store */
        $store = $request->get('partner_store');
        /** @var User $dropshipper */
        $dropshipper = $request->get('partner_user');

        $order = Order::with(['orderStatus', 'deliveryInformation', 'items'])
            ->where('user_id', $dropshipper->id)
            ->where(function ($q) use ($orderNumber) {
                $q->where('order_number', $orderNumber)
                  ->orWhere('note', 'like', "%[ExtOrder:{$orderNumber}]%");
            })
            ->first();

        if (!$order) {
            return $this->failed("Order '{$orderNumber}' not found for this partner store.", null, 404);
        }

        $courier = null;
        if ($order->deliveryInformation) {
            $info = $order->deliveryInformation;
            $courier = [
                'consignment_id' => $info->consignment_id,
                'tracking_code' => $info->consignment_id,
                'delivery_fee' => (float) $info->delivery_fee,
                'total_fee' => (float) $info->total_fee,
            ];
        }

        return $this->success('Order status retrieved successfully', [
            'order_number' => $order->order_number,
            'status_id' => $order->status,
            'status_name' => $order->orderStatus?->name ?? 'Pending',
            'payment_status' => $order->payment_status,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'subtotal' => (float) $order->subtotal,
            'shipping_fee' => (float) $order->shipping_fee,
            'total' => (float) $order->total,
            'reseller_profit' => (float) $order->reseller_profit,
            'courier' => $courier,
            'items_count' => $order->items->count(),
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * POST /api/partner/v1/webhook/test
     * Test webhook delivery to external client store
     */
    public function testWebhook(Request $request): JsonResponse
    {
        /** @var PartnerStore $store */
        $store = $request->get('partner_store');

        if (empty($store->webhook_url)) {
            return $this->failed('Webhook URL is not configured for this partner store.', null, 422);
        }

        $payload = [
            'event' => 'ping',
            'timestamp' => now()->toIso8601String(),
            'store_id' => $store->id,
            'store_name' => $store->store_name,
            'message' => 'ResellerBrain Partner Webhook connection test successful.',
        ];

        $payloadJson = json_encode($payload);
        $signature = hash_hmac('sha256', $payloadJson, $store->webhook_secret ?? $store->api_secret);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-RB-Signature' => $signature,
                    'User-Agent' => 'ResellerBrain-Webhook/1.0',
                ])
                ->post($store->webhook_url, $payload);

            return $this->success('Webhook test dispatched', [
                'webhook_url' => $store->webhook_url,
                'http_status' => $response->status(),
                'response_body' => Str::limit($response->body(), 300),
                'success' => $response->successful(),
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Webhook dispatch failed: ' . $e->getMessage(), null, 502);
        }
    }

    // ==========================================
    // RESELLERBRAIN DASHBOARD MANAGEMENT API (Authenticated via ResellerBrain User Token)
    // ==========================================

    /**
     * GET /api/partner/store
     * Fetch the partner store credentials & details for the logged-in dropshipper
     */
    public function getStore(Request $request): JsonResponse
    {
        $user = $request->get('api_user') ?: $request->user();
        if (!$user) {
            return $this->failed('Unauthenticated', null, 401);
        }

        $store = PartnerStore::where('user_id', $user->id)->first();

        return $this->success('Store details retrieved', [
            'has_store' => (bool) $store,
            'store' => $store ? [
                'id' => $store->id,
                'store_name' => $store->store_name,
                'store_domain' => $store->store_domain,
                'store_url' => $store->store_url,
                'api_key' => $store->api_key,
                'api_secret' => $store->api_secret, // Exposed to owner in their own dashboard
                'webhook_url' => $store->webhook_url,
                'webhook_secret' => $store->webhook_secret,
                'is_active' => (bool) $store->is_active,
                'last_sync_at' => $store->last_sync_at?->toIso8601String(),
                'created_at' => $store->created_at?->toIso8601String(),
            ] : null,
        ]);
    }

    /**
     * POST /api/partner/store/save
     * Create or update the partner store credentials
     */
    public function saveStore(Request $request): JsonResponse
    {
        $user = $request->get('api_user') ?: $request->user();
        if (!$user) {
            return $this->failed('Unauthenticated', null, 401);
        }

        $validator = Validator::make($request->all(), [
            'store_name' => 'required|string|max:191',
            'store_domain' => 'nullable|string|max:191',
            'store_url' => 'nullable|url|max:255',
            'webhook_url' => 'nullable|url|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->failed('Validation error', $validator->errors(), 422);
        }

        $store = PartnerStore::where('user_id', $user->id)->first();

        if (!$store) {
            $store = PartnerStore::create([
                'user_id' => $user->id,
                'store_name' => $request->input('store_name'),
                'store_domain' => $request->input('store_domain'),
                'store_url' => $request->input('store_url'),
                'api_key' => PartnerStore::generateApiKey(),
                'api_secret' => PartnerStore::generateApiSecret(),
                'webhook_url' => $request->input('webhook_url'),
                'webhook_secret' => PartnerStore::generateWebhookSecret(),
                'is_active' => true,
            ]);
            $message = 'Partner store created and API keys generated successfully';
        } else {
            $store->update([
                'store_name' => $request->input('store_name', $store->store_name),
                'store_domain' => $request->input('store_domain', $store->store_domain),
                'store_url' => $request->input('store_url', $store->store_url),
                'webhook_url' => $request->input('webhook_url', $store->webhook_url),
                'is_active' => $request->has('is_active') ? $request->boolean('is_active') : $store->is_active,
            ]);
            $message = 'Partner store updated successfully';
        }

        return $this->success($message, [
            'id' => $store->id,
            'store_name' => $store->store_name,
            'store_domain' => $store->store_domain,
            'store_url' => $store->store_url,
            'api_key' => $store->api_key,
            'api_secret' => $store->api_secret,
            'webhook_url' => $store->webhook_url,
            'webhook_secret' => $store->webhook_secret,
            'is_active' => (bool) $store->is_active,
        ]);
    }

    /**
     * POST /api/partner/store/regenerate-secret
     * Regenerate API Secret
     */
    public function regenerateSecret(Request $request): JsonResponse
    {
        $user = $request->get('api_user') ?: $request->user();
        if (!$user) {
            return $this->failed('Unauthenticated', null, 401);
        }

        $store = PartnerStore::where('user_id', $user->id)->first();
        if (!$store) {
            return $this->failed('Partner store not found', null, 404);
        }

        $newSecret = PartnerStore::generateApiSecret();
        $store->update(['api_secret' => $newSecret]);

        return $this->success('API Secret regenerated successfully. Update this in your external store configuration.', [
            'api_key' => $store->api_key,
            'api_secret' => $newSecret,
        ]);
    }
}
