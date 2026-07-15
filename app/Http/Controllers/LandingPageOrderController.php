<?php

namespace App\Http\Controllers;

use App\Models\District;
use App\Models\Division;
use App\Models\LandingPageOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ResellerProductPage;
use App\Models\Upazila;
use App\Service\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LandingPageOrderController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

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

    private function relations(): array
    {
        return [
            'resellerProductPage',
            'reseller',
            'product.images.image',
            'product.primaryImage',
            'order.items',
            'district',
            'division',
            'upazila',
        ];
    }

    private function uniqueTrackingCode(): string
    {
        do {
            $code = 'LPO-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (LandingPageOrder::where('tracking_code', $code)->exists());

        return $code;
    }

    private function uniqueOrderNumber(): string
    {
        do {
            $number = 'ORD-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (Order::where('order_number', $number)->exists());

        return $number;
    }

    private function hydrateFromProductPage(array $data): array
    {
        $page = ResellerProductPage::find($data['reseller_product_page_id']);

        if (!$page) {
            return $data;
        }

        $data['reseller_id'] = $data['reseller_id'] ?? $page->reseller_id;
        $data['product_id'] = $data['product_id'] ?? $page->product_id;
        $data['selling_price'] = $data['selling_price']
            ?? $page->discount_price
            ?? $page->selling_price
            ?? 0;
        $data['delivery_charge'] = $data['delivery_charge'] ?? $page->delivery_charge ?? 0;

        return $data;
    }

    private function calculateTotal(array $data): float
    {
        $quantity = (int) ($data['quantity'] ?? 1);
        $sellingPrice = (float) ($data['selling_price'] ?? 0);
        $deliveryCharge = (float) ($data['delivery_charge'] ?? 0);

        return round(($sellingPrice * $quantity) + $deliveryCharge, 2);
    }

    private function notifyLandingOrderCreated(LandingPageOrder $landingOrder): void
    {
        $amount = number_format((float) $landingOrder->total_amount, 2, '.', '');

        if ($landingOrder->reseller_id) {
            $this->notificationService->createNotificationSafely([
                'title'      => 'New Landing Page Order',
                'subtitle'   => "Order {$landingOrder->tracking_code} received. Amount {$amount} BDT.",
                'created_by' => null,
                'send_to'    => $landingOrder->reseller_id,
                'is_seen'    => false,
                'type'       => 'order',
                'is_active'  => true,
                'image'      => null,
                'module'     => 'landing_page_order',
            ]);
        }

        $this->notificationService->createAdminNotifications([
            'title'      => 'New Landing Page Order',
            'subtitle'   => "Order {$landingOrder->tracking_code} received. Amount {$amount} BDT.",
            'created_by' => $landingOrder->reseller_id,
            'is_seen'    => false,
            'type'       => 'order',
            'is_active'  => true,
            'image'      => null,
            'module'     => 'landing_page_order',
        ]);
    }

    private function notifyLandingOrderPassed(LandingPageOrder $landingOrder, Order $order): void
    {
        $order->loadMissing('vendor.user');
        $amount = number_format((float) $order->total, 2, '.', '');

        if ($landingOrder->reseller_id) {
            $this->notificationService->createNotificationSafely([
                'title'      => 'Landing Order Passed',
                'subtitle'   => "Landing order {$landingOrder->tracking_code} passed as {$order->order_number}. Amount {$amount} BDT.",
                'created_by' => null,
                'send_to'    => $landingOrder->reseller_id,
                'is_seen'    => false,
                'type'       => 'order',
                'is_active'  => true,
                'image'      => null,
                'module'     => 'landing_page_order',
            ]);
        }

        if ($order->vendor?->user_id) {
            $this->notificationService->createNotificationSafely([
                'title'      => 'New Vendor Order',
                'subtitle'   => "Landing order {$landingOrder->tracking_code} passed as {$order->order_number}.",
                'created_by' => $landingOrder->reseller_id,
                'send_to'    => $order->vendor->user_id,
                'is_seen'    => false,
                'type'       => 'order',
                'is_active'  => true,
                'image'      => null,
                'module'     => 'landing_page_order',
            ]);
        }

        $this->notificationService->createAdminNotifications([
            'title'      => 'Landing Order Passed',
            'subtitle'   => "Landing order {$landingOrder->tracking_code} passed as {$order->order_number}. Amount {$amount} BDT.",
            'created_by' => $landingOrder->reseller_id,
            'is_seen'    => false,
            'type'       => 'order',
            'is_active'  => true,
            'image'      => null,
            'module'     => 'landing_page_order',
        ]);
    }

    public function list(Request $request)
    {
        try {
            $query = LandingPageOrder::with($this->relations());

            if ($request->filled('reseller_id')) {
                $query->where('reseller_id', $request->reseller_id);
            }

            if ($request->filled('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            if ($request->filled('reseller_product_page_id')) {
                $query->where('reseller_product_page_id', $request->reseller_product_page_id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('tracking_code')) {
                $query->where('tracking_code', $request->tracking_code);
            }

            if ($request->filled('all') && (int) $request->get('all') === 1) {
                return $this->success('Landing page orders fetched successfully', $query->latest()->get());
            }

            $perPage = (int) $request->get('per_page', 20);

            return $this->success('Landing page orders fetched successfully', $query->latest()->paginate($perPage));
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function add(Request $request)
    {
        try {
            $validated = $request->validate([
                'reseller_product_page_id' => ['required', 'integer', 'exists:reseller_product_pages,id'],
                'reseller_id' => ['nullable', 'integer', 'exists:users,id'],
                'product_id' => ['nullable', 'integer', 'exists:products,id'],
                'customer_name' => ['required', 'string', 'max:255'],
                'customer_phone' => ['required', 'string', 'max:50'],
                'customer_address' => ['required', 'string'],
                'district_id' => ['nullable', 'integer', 'exists:districts,id'],
                'division_id' => ['nullable', 'integer', 'exists:divisions,id'],
                'upozella_id' => ['nullable', 'integer', 'exists:upazilas,id'],
                'variant_id' => ['nullable', 'integer', 'exists:product_attributes,id'],
                'quantity' => ['nullable', 'integer', 'min:1'],
                'selling_price' => ['nullable', 'numeric', 'min:0'],
                'delivery_charge' => ['nullable', 'numeric', 'min:0'],
                'total_amount' => ['nullable', 'numeric', 'min:0'],
                'status' => ['nullable', 'string', 'max:50'],
                'is_outside_dhaka' => ['nullable', 'boolean'],
                'source' => ['nullable', 'string', 'max:100'],
                'tracking_code' => ['nullable', 'string', 'max:100', 'unique:landing_page_orders,tracking_code'],
            ]);

            $validated = $this->hydrateFromProductPage($validated);
            $validated['quantity'] = $validated['quantity'] ?? 1;
            $validated['status'] = $validated['status'] ?? 'pending';
            $validated['source'] = $validated['source'] ?? 'landing_page';
            $validated['tracking_code'] = $validated['tracking_code'] ?? $this->uniqueTrackingCode();
            $validated['total_amount'] = $validated['total_amount'] ?? $this->calculateTotal($validated);

            $landingOrder = LandingPageOrder::create($validated);
            $this->notifyLandingOrderCreated($landingOrder);

            return $this->success('Landing page order created successfully', $landingOrder->load($this->relations()), 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function details($id)
    {
        try {
            $landingOrder = LandingPageOrder::with($this->relations())->find($id);

            if (!$landingOrder) {
                return $this->failed('Landing page order not found', null, 404);
            }

            return $this->success('Landing page order fetched successfully', $landingOrder);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $landingOrder = LandingPageOrder::find($id);

            if (!$landingOrder) {
                return $this->failed('Landing page order not found', null, 404);
            }

            $validated = $request->validate([
                'reseller_product_page_id' => ['sometimes', 'integer', 'exists:reseller_product_pages,id'],
                'reseller_id' => ['sometimes', 'integer', 'exists:users,id'],
                'product_id' => ['sometimes', 'integer', 'exists:products,id'],
                'customer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'customer_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
                'customer_address' => ['sometimes', 'nullable', 'string'],
                'district_id' => ['sometimes', 'nullable', 'integer', 'exists:districts,id'],
                'division_id' => ['sometimes', 'nullable', 'integer', 'exists:divisions,id'],
                'upozella_id' => ['sometimes', 'nullable', 'integer', 'exists:upazilas,id'],
                'variant_id' => ['sometimes', 'nullable', 'integer', 'exists:product_attributes,id'],
                'quantity' => ['sometimes', 'integer', 'min:1'],
                'selling_price' => ['sometimes', 'numeric', 'min:0'],
                'delivery_charge' => ['sometimes', 'numeric', 'min:0'],
                'total_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'status' => ['sometimes', 'nullable', 'string', 'max:50'],
                'is_outside_dhaka' => ['sometimes', 'nullable', 'boolean'],
                'source' => ['sometimes', 'nullable', 'string', 'max:100'],
                'tracking_code' => ['sometimes', 'nullable', 'string', 'max:100', 'unique:landing_page_orders,tracking_code,' . $landingOrder->id],
            ]);

            if (isset($validated['reseller_product_page_id'])) {
                $validated = $this->hydrateFromProductPage($validated + $landingOrder->toArray());
            }

            if (
                array_key_exists('quantity', $validated)
                || array_key_exists('selling_price', $validated)
                || array_key_exists('delivery_charge', $validated)
                || array_key_exists('total_amount', $validated)
            ) {
                $merged = array_merge($landingOrder->toArray(), $validated);
                $validated['total_amount'] = $validated['total_amount'] ?? $this->calculateTotal($merged);
            }

            $landingOrder->update($validated);

            return $this->success('Landing page order updated successfully', $landingOrder->load($this->relations()));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function delete($id)
    {
        try {
            $landingOrder = LandingPageOrder::find($id);

            if (!$landingOrder) {
                return $this->failed('Landing page order not found', null, 404);
            }

            $landingOrder->delete();

            return $this->success('Landing page order deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function passOrderToResellerBrain($id)
    {
        try {
            $landingOrder = LandingPageOrder::with(['product', 'order.items'])->find($id);

            if (!$landingOrder) {
                return $this->failed('Landing page order not found', null, 404);
            }

            if ($landingOrder->order_id && $landingOrder->order) {
                return $this->success('Landing page order already passed to ResellerBrain', [
                    'landing_page_order' => $landingOrder->load($this->relations()),
                    'order' => $landingOrder->order,
                ]);
            }

            $product = $landingOrder->product ?: Product::find($landingOrder->product_id);
            if (!$product) {
                return $this->failed('Product not found for this landing page order', null, 404);
            }

            $order = DB::transaction(function () use ($landingOrder, $product) {
                $quantity = max(1, (int) $landingOrder->quantity);
                $sellingPrice = round((float) $landingOrder->selling_price, 2);
                $unitPrice = round((float) ($product->unit_price ?? 0), 2);
                $deliveryCharge = round((float) $landingOrder->delivery_charge, 2);
                $subtotal = round($sellingPrice * $quantity, 2);
                $resellerProfit = round(($sellingPrice - $unitPrice) * $quantity, 2);
                $total = round((float) ($landingOrder->total_amount ?: ($subtotal + $deliveryCharge)), 2);

                $districtName = $landingOrder->district_id
                    ? District::where('id', $landingOrder->district_id)->value('name')
                    : null;
                $divisionName = $landingOrder->division_id
                    ? Division::where('id', $landingOrder->division_id)->value('name')
                    : null;
                $upazilaName = $landingOrder->upozella_id
                    ? Upazila::where('id', $landingOrder->upozella_id)->value('name')
                    : null;

                $order = Order::create([
                    'user_id' => $landingOrder->reseller_id,
                    'order_number' => $this->uniqueOrderNumber(),
                    'status' => 1,
                    'payment_status' => 'unpaid',
                    'customer_name' => $landingOrder->customer_name,
                    'customer_phone' => $landingOrder->customer_phone,
                    'shipping_address' => $landingOrder->customer_address,
                    'zone' => $divisionName ?: $landingOrder->division_id,
                    'district' => $districtName ?: $landingOrder->district_id,
                    'area' => $upazilaName ?: $landingOrder->upozella_id,
                    'subtotal' => $subtotal,
                    'reseller_price' => $subtotal,
                    'shipping_fee' => $deliveryCharge,
                    'discount' => 0,
                    'total' => $total,
                    'reseller_profit' => $resellerProfit,
                    'vendor_id' => $product->vendor_id,
                    'note' => 'Created from landing page order #' . $landingOrder->id . ' tracking ' . $landingOrder->tracking_code,
                ]);

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'shop_id' => $product->vendor_id,
                    'attribute_id' => $landingOrder->variant_id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'unit_price' => $unitPrice,
                    'reseller_price' => $sellingPrice,
                    'qty' => $quantity,
                    'line_total' => $subtotal,
                    'line_total_reseller_profit' => $resellerProfit,
                    'status' => 1,
                    'note' => 'Created from landing page order #' . $landingOrder->id,
                ]);

                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'status_id' => 1,
                    'note' => 'Order created from landing page order #' . $landingOrder->id,
                    'changed_by' => $landingOrder->reseller_id,
                ]);

                $landingOrder->update([
                    'order_id' => $order->id,
                    'status' => 'passed_to_reseller_brain',
                    'passed_at' => now(),
                ]);

                return $order->load(['items', 'user', 'vendor']);
            });

            $this->notifyLandingOrderPassed($landingOrder->fresh(), $order);

            return $this->success('Landing page order passed to ResellerBrain successfully', [
                'landing_page_order' => $landingOrder->fresh()->load($this->relations()),
                'order' => $order,
            ], 201);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
