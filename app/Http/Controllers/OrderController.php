<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrderStatusHistory;
use App\Models\Transaction;
use App\Models\OrderItem;
use App\Models\OrderErrorLog;
use App\Models\OrderSettlement;
use App\Models\ResellerTransaction;
use App\Models\User;
use App\Models\DeliveryCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Service\NotificationService;
use App\Service\CarrybeeService;

class OrderController extends Controller
{
    private function maskSensitive(array $payload): array
    {
        unset($payload['password'], $payload['password_confirmation']);
        return $payload;
    }

    private function resolveCheckoutUser(Request $request): ?User
    {
        $userId = $request->input('user_id');

        if (!empty($userId)) {
            return User::find($userId);
        }

        return null;
    }

    private function storeOrderError(Request $request, string $message, ?User $user = null, string $level = 'error', ?\Throwable $exception = null): void
    {
        try {
            OrderErrorLog::create([
                'user_id' => $user?->id,
                'level' => $level,
                'message' => $message,
                'file' => $exception?->getFile(),
                'line' => $exception?->getLine(),
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'request_data' => json_encode($this->maskSensitive($request->all()), JSON_UNESCAPED_UNICODE),
                'stack_trace' => $exception?->getTraceAsString(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Do not interrupt checkout response if logging fails.
        }
    }

    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data
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

    private function getCarrybeeLogisticData(Order $order): array
    {
        $order->loadMissing('deliveryInformation');

        $deliveryInfo = $order->deliveryInformation;
        $fallbackCollectable = (float) ($deliveryInfo?->collectable_amount ?? $order->total ?? 0);
        $fallbackDeliveryFee = (float) ($deliveryInfo?->delivery_fee ?? $order->shipping_fee ?? 0);
        $fallbackCodFee = 0;

        if (!$deliveryInfo?->consignment_id) {
            return [
                'collectable_amount' => 0,
                'delivery_fee' => 0,
                'cod_fee' => 0,
                'has_consignment' => false,
                'source' => 'no_delivery_assignment',
            ];
        }

        try {
            $deliveryCompany = null;
            if ($deliveryInfo->delivery_company_id) {
                $deliveryCompany = DeliveryCompany::where('id', $deliveryInfo->delivery_company_id)
                    ->where('is_active', true)
                    ->first();
            }

            if (!$deliveryCompany) {
                $deliveryCompany = DeliveryCompany::where('is_active', true)->latest()->first();
            }

            if (!$deliveryCompany || !$deliveryCompany->api_key || !$deliveryCompany->secret_key || !$deliveryCompany->client_context) {
                return [
                    'collectable_amount' => $fallbackCollectable,
                    'delivery_fee' => $fallbackDeliveryFee,
                    'cod_fee' => $fallbackCodFee,
                    'has_consignment' => true,
                    'source' => 'local',
                ];
            }

            $service = new CarrybeeService(
                $deliveryCompany->api_key,
                $deliveryCompany->secret_key,
                $deliveryCompany->client_context
            );

            $result = $service->getOrderDetails($deliveryInfo->consignment_id);
            $body = $result['body'] ?? [];
            $details = $body['data']['data'] ?? $body['data'] ?? [];

            if (($result['status'] ?? 500) >= 400 || !is_array($details)) {
                return [
                    'collectable_amount' => $fallbackCollectable,
                    'delivery_fee' => $fallbackDeliveryFee,
                    'cod_fee' => $fallbackCodFee,
                    'has_consignment' => true,
                    'source' => 'local',
                ];
            }

            return [
                'collectable_amount' => (float) ($details['collectable_amount'] ?? $fallbackCollectable),
                'delivery_fee' => (float) ($details['delivery_fee'] ?? $fallbackDeliveryFee),
                'cod_fee' => (float) ($details['cod_fee'] ?? $fallbackCodFee),
                'has_consignment' => true,
                'source' => 'carrybee',
            ];
        } catch (\Throwable $e) {
            return [
                'collectable_amount' => $fallbackCollectable,
                'delivery_fee' => $fallbackDeliveryFee,
                'cod_fee' => $fallbackCodFee,
                'has_consignment' => true,
                'source' => 'local',
            ];
        }
    }

    private function createOrderSettlements(Order $order, ?int $createdBy = null): void
    {
        $order->loadMissing(['items.shop']);

        $itemsByVendor = $order->items->groupBy('shop_id');
        foreach ($itemsByVendor as $vendorId => $items) {
            if (empty($vendorId)) {
                continue;
            }

            $vendorAmount = round($items->sum(function ($item) {
                return (float) ($item->unit_price ?? 0) * (int) ($item->qty ?? 0);
            }), 2);

            $vendor = $items->first()?->shop;

            OrderSettlement::updateOrCreate(
                [
                    'order_id' => $order->id,
                    'settlement_type' => OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE,
                    'vendor_id' => $vendorId,
                ],
                [
                    'payable_user_id' => $vendor?->user_id,
                    'user_type' => 'vendor',
                    'settleable_amount' => $vendorAmount,
                    'currency' => 'BDT',
                    'status' => OrderSettlement::STATUS_PENDING,
                    'admin_note' => 'Supplier product price settlement for order #' . $order->order_number,
                    'trx_id' => 'SET-' . $order->id . '-SUP-' . $vendorId,
                    'created_by' => $createdBy,
                ]
            );
        }

        $resellerProfit = round((float) ($order->reseller_profit ?? 0), 2);
        OrderSettlement::updateOrCreate(
            [
                'order_id' => $order->id,
                'settlement_type' => OrderSettlement::TYPE_RESELLER_PROFIT,
                'payable_user_id' => $order->user_id,
            ],
            [
                'vendor_id' => null,
                'user_type' => 'dropshipper',
                'settleable_amount' => $resellerProfit,
                'currency' => 'BDT',
                'status' => OrderSettlement::STATUS_PENDING,
                'admin_note' => 'Dropshipper profit settlement for order #' . $order->order_number,
                'trx_id' => 'SET-' . $order->id . '-RES-' . $order->user_id,
                'created_by' => $createdBy,
            ]
        );

        $logisticData = $this->getCarrybeeLogisticData($order);
        $collectableAmount = round((float) $logisticData['collectable_amount'], 2);
        $chargedShippingFee = round((float) ($order->shipping_fee ?? 0), 2);
        $deliveryFee = round((float) $logisticData['delivery_fee'], 2);
        $codFee = round((float) $logisticData['cod_fee'], 2);
        $totalDeliveryCost = round($deliveryFee + $codFee, 2);
        $hasConsignment = (bool) $logisticData['has_consignment'];
        $logisticSource = $logisticData['source'];

        OrderSettlement::updateOrCreate(
            [
                'order_id' => $order->id,
                'settlement_type' => OrderSettlement::TYPE_SHIPPING_CHARGE,
                'payable_user_id' => null,
            ],
            [
                'vendor_id' => null,
                'user_type' => 'shipping',
                'settleable_amount' => $hasConsignment ? $totalDeliveryCost : 0,
                'currency' => 'BDT',
                'status' => $hasConsignment ? OrderSettlement::STATUS_SETTLED : OrderSettlement::STATUS_PENDING,
                'admin_note' => $hasConsignment
                    ? 'Shipping charge already settled for order #' . $order->order_number . " (delivery_fee {$deliveryFee} + cod_fee {$codFee} = {$totalDeliveryCost}, {$logisticSource})"
                    : 'Shipping charge not settled: no delivery assignment consignment for order #' . $order->order_number,
                'trx_id' => 'SET-' . $order->id . '-SHP',
                'created_by' => $createdBy,
                'settled_at' => $hasConsignment ? now() : null,
            ]
        );

        $logisticEarning = $hasConsignment ? round($chargedShippingFee - $totalDeliveryCost, 2) : 0;
        OrderSettlement::updateOrCreate(
            [
                'order_id' => $order->id,
                'settlement_type' => OrderSettlement::TYPE_COMPANY_LOGISTIC_EARNING,
                'payable_user_id' => null,
            ],
            [
                'vendor_id' => null,
                'user_type' => 'company',
                'settleable_amount' => $logisticEarning,
                'currency' => 'BDT',
                'status' => OrderSettlement::STATUS_PENDING,
                'admin_note' => $hasConsignment
                    ? 'Company logistic earning for order #' . $order->order_number . " (shipping_fee {$chargedShippingFee} - total_delivery_cost {$totalDeliveryCost}; delivery_fee {$deliveryFee}, cod_fee {$codFee}, {$logisticSource}, collectable {$collectableAmount})"
                    : 'Company logistic earning not calculated: no delivery assignment consignment for order #' . $order->order_number,
                'trx_id' => 'SET-' . $order->id . '-COM-LOG',
                'created_by' => $createdBy,
            ]
        );

        OrderSettlement::updateOrCreate(
            [
                'order_id' => $order->id,
                'settlement_type' => OrderSettlement::TYPE_COMPANY_PRODUCT_COMMISSION,
                'payable_user_id' => null,
            ],
            [
                'vendor_id' => null,
                'user_type' => 'company',
                'settleable_amount' => 0,
                'currency' => 'BDT',
                'status' => OrderSettlement::STATUS_PENDING,
                'admin_note' => 'Company product commission earning for order #' . $order->order_number . ' (commission structure not configured)',
                'trx_id' => 'SET-' . $order->id . '-COM-COM',
                'created_by' => $createdBy,
            ]
        );
    }
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }
    /**
     * POST /orders/checkout
     * Body: user_id, customer_name, customer_phone, shipping_address, zone, district, area, lat, lon, note
     *
     * Converts ACTIVE cart -> order + order_items in ONE DB transaction
     */
    public function checkout(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => ['required', 'integer', 'exists:users,id'],

                'customer_name' => ['nullable', 'string', 'max:255'],
                'customer_phone' => ['nullable', 'string', 'max:50'],
                'shipping_address' => ['nullable', 'string', 'max:1000'],

                'zone' => ['nullable', 'string', 'max:100'],
                'district' => ['nullable', 'string', 'max:100'],
                'area' => ['nullable', 'string', 'max:100'],
                'lat' => ['nullable', 'numeric'],
                'lon' => ['nullable', 'numeric'],

                'note' => ['nullable', 'string'],
            ]);

            $cart = Cart::where('user_id', $validated['user_id'])
                ->where('status', 'active')
                ->latest()
                ->first();

            if (!$cart) {
                $this->storeOrderError($request, 'Checkout failed: active cart not found', User::find($validated['user_id']));
                return $this->failed('Active cart not found', null, 404);
            }

            $cartItems = CartItem::with(['product'])
                ->where('cart_id', $cart->id)
                ->get();

            if ($cartItems->count() === 0) {
                $this->storeOrderError($request, 'Checkout failed: cart is empty', User::find($validated['user_id']), 'warning');
                return $this->failed('Cart is empty', null, 409);
            }

            DB::beginTransaction();

            // Recalculate subtotal from cart_items (server truth)
            $subtotal = 0;
            $resellerProfit = 0;
            foreach ($cartItems as $ci) {
                $subtotal += (float) ($ci->line_total ?? 0);
                $resellerProfit += (float) ($ci->line_total_reseller_profit ?? 0);
            }


            // For now: shipping_fee & discount are kept null (or 0) until you add those modules
            $shippingFee = request()->input('delivery_charge', 0);
            $discount = 0;
            $total = round(($subtotal + $shippingFee) - $discount, 2);

            // Generate an order_number that is human-friendly and unique
            $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(Str::random(6));

            $order = Order::create([
                'user_id' => $validated['user_id'],
                'order_number' => $orderNumber,

                'status' => 1,
                'payment_status' => 'unpaid',

                'customer_name' => $validated['customer_name'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'shipping_address' => $validated['shipping_address'] ?? null,

                'zone' => $validated['zone'] ?? null,
                'district' => $validated['district'] ?? null,
                'area' => $validated['area'] ?? null,
                'lat' => $validated['lat'] ?? null,
                'lon' => $validated['lon'] ?? null,

                'subtotal' => round($subtotal, 2),
                'reseller_profit' => round($resellerProfit, 2),
                'shipping_fee' => $shippingFee,
                'discount' => $discount,
                'total' => $total,
                'vendor_id' => $cartItems->first()?->shop_id ?? null,

                'note' => $validated['note'] ?? null,
            ]);

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status_id' => 1,
                'note' => 'Order created via checkout',
                'changed_by' => $validated['user_id'],
            ]);

            foreach ($cartItems as $ci) {
                $product = $ci->product;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $ci->product_id,
                    'shop_id' => $ci->shop_id,
                    'attribute_id' => $ci->attribute_id,

                    // Snapshot important product fields
                    'product_name' => $product ? ($product->name ?? null) : null,
                    'sku' => $product ? ($product->sku ?? null) : null,

                    // Snapshot cart-time pricing
                    'unit_price' => $ci->unit_price,
                    'reseller_price' => $ci->reseller_price,
                    'qty' => $ci->qty,
                    'line_total' => $ci->line_total,
                    'line_total_reseller_profit' => $ci->line_total_reseller_profit,

                    'status' => 1,
                    'note' => $ci->note ?? null,
                ]);
            }

            // Mark cart as checked_out and clear items
            $cart->status = 'checked_out';
            $cart->save();

            CartItem::where('cart_id', $cart->id)->delete();

            DB::commit();

            $order->load(['items']);
            $this->notificationService->createNotification([
                'title'      => 'New Order Created',
                'subtitle'   => "Order ID: {$order->id}",
                'created_by' => $order->user_id,
                'send_to'    => $order->user_id,
                'is_seen'    => false,
                'type'       => 'order',
                'is_active'  => true,
                'image'      => null, // Set image path if needed
                'module'     => 'order',
            ]);
            return $this->success('Checkout successful. Order created.', $order, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->storeOrderError($request, 'Checkout validation failed', $this->resolveCheckoutUser($request), 'warning', $e);
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->storeOrderError($request, 'Checkout failed with server error', $this->resolveCheckoutUser($request), 'error', $e);
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /orders/list/{userId}?per_page=20
     * List orders for a customer
     */
    public function listOrdersByUser($userId, Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 20);

            $orders = Order::where('user_id', $userId)->with([ 'status'])
                ->latest()
                ->paginate($perPage);

            return $this->success('Orders fetched successfully', $orders);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
    public function updateOrder(Request $request, $id)
    {
        try {
            $order = Order::find($id);
            if (!$order) {
                return $this->failed('Order not found', null, 404);
            }

            $validated = $request->validate([
                'customer_name'    => ['nullable', 'string', 'max:255'],
                'customer_phone'   => ['nullable', 'string', 'max:50'],
                'shipping_address' => ['nullable', 'string', 'max:1000'],
                'zone'             => ['nullable', 'string', 'max:100'],
                'district'         => ['nullable', 'string', 'max:100'],
                'area'             => ['nullable', 'string', 'max:100'],
                'lat'              => ['nullable', 'numeric'],
                'lon'              => ['nullable', 'numeric'],
                'note'             => ['nullable', 'string'],
            ]);

            $order->update($validated);

            return $this->success('Order updated successfully', $order);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /orders/vendor/{vendorId}?per_page=20
     * List orders for a vendor by shop_id in order items
     */
    public function vendorOrderList($vendorId, Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 20);

            // Get all order_ids that have items belonging to this vendor's shop
            $orderIds = OrderItem::where('shop_id', $vendorId)
                ->pluck('order_id')
                ->unique();

            if ($orderIds->isEmpty()) {
                return $this->failed('No orders found for this vendor', null, 404);
            }

            $orders = Order::with([
                'items' => function ($query) use ($vendorId) {
                    // Only return items that belong to this vendor's shop
                    $query->where('shop_id', $vendorId)->with('shop');
                },
                'statusHistory.status',
                'deliveryInformation',
            ])
                ->whereIn('id', $orderIds)
                ->where('status', '!=', 1)
                ->latest()
                ->paginate($perPage);

            return $this->success('Vendor orders fetched successfully', $orders);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
    public function allOrders(Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 20);

            $orders = Order::with(['status', 'user', 'vendor', 'items.shop'])
                ->latest()
                ->paginate($perPage);

        

            return $this->success('Orders fetched successfully', $orders);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /orders/completed
     * List all completed orders (admin)
     */
    public function completedOrders(Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 20);

            $orders = Order::where('status', 9)
                ->with(['items', 'user'])
                ->latest()
                ->paginate($perPage);

            return $this->success('Completed orders fetched successfully', $orders);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /orders/completed/{userId}
     * List completed orders for a specific user
     */
    public function completedOrdersByUser($userId, Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 20);

            $orders = Order::where('user_id', $userId)
                ->whereIn('status', [9, 10])
                ->with(['items'])
                ->latest()
                ->paginate($perPage);

            return $this->success('User completed orders fetched successfully', $orders);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /orders/details/{id}
     */
    public function getOrderDetails($id)
    {
        try {
            $order = Order::with([
                'user',
                'vendor',
                'status',
                'items.shop',
                'items.productAttribute.attribute', 
                'items.productAttribute.value',
                'deliveryMan.deliveryMan',
                'deliveryInformation',
                'statusHistory.status',
                'carryBeeDraft', 
                'settlements.payableUser',
                'settlements.vendor',
            ])->find($id);

            if (!$order) {
                return $this->failed('Order not found', null, 404);
            }

            return $this->success('Order fetched successfully', $order);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PATCH /orders/status/{id}
     * Body: status
     */
    public function updateOrderStatus(Request $request, $id)
    {
        try {
            $order = Order::find($id);
            if (!$order) {
                return $this->failed('Order not found', null, 404);
            }

            $validated = $request->validate([
                'status_id' => ['required', 'integer', 'exists:order_statuses,id'],
                'note' => ['nullable', 'string'],
            ]);

            $order->status = $validated['status_id'];
            $order->save();

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status_id' => $validated['status_id'],
                'note' => $validated['note'] ?? null,
                'changed_by' => null,
            ]);

            $statusId = (int) $validated['status_id'];
            if ($statusId === 9) {
                $order->payment_status = 'paid';
                $order->save();
                $hasCredit = Transaction::where('order_id', $order->id)
                    ->where('trx_type', 'credit')
                    ->exists();

                if (!$hasCredit) {
                    Transaction::create([
                        'amount' => $order->total,
                        'trx_type' => 'credit',
                        'status' => 'completed',
                        'source' => 'cod',
                        'order_id' => $order->id,
                        'type' => 'order_status',
                        'note' => 'Credit transaction for order #' . $order->order_number,
                    ]);
                }

                $this->createOrderSettlements($order, $request->input('changed_by'));
            }



            return $this->success('Order status updated successfully', $order);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function settleResellerProfit(Request $request, $id)
    {
        try {
            $order = Order::find($id);
            if (!$order) {
                return $this->failed('Order not found', null, 404);
            }

            $user = User::find($order->user_id);
            if (!$user) {
                return $this->failed('User not found', null, 404);
            }

            if ($user->user_type === 'admin') {
                $statusId = $order->status;

                if ($statusId === '9') {
                    // Check if debit transaction already exists
                    $hasDebit = Transaction::where('order_id', $order->id)
                        ->where('trx_type', 'debit')
                        ->exists();
                    if ($hasDebit) {
                        return $this->failed('Debit transaction already exists for this order', null, 409);
                    }

                    // Update order status
                    $order->status = 10;
                    $order->save();

                    // Add status history
                    OrderStatusHistory::create([
                        'order_id' => $order->id,
                        'status_id' => 10,
                        'note' => 'Settled reseller profit for order',
                        'changed_by' => $request->changed_by,
                    ]);

                    // Create debit transaction
                    $profitAmount = (float) ($order->reseller_profit ?? 0);
                    Transaction::create([
                        'amount' => $profitAmount,
                        'trx_type' => 'debit',
                        'status' => 'completed',
                        'source' => 'Admin Action',
                        'order_id' => $order->id,
                        'type' => 'order_status',
                        'note' => 'Debit transaction (reseller profit) for order #' . $order->order_number,
                    ]);
                    ResellerTransaction::create([
                        'amount' => $profitAmount,
                        'trx_type' => 'credit',
                        'status' => 'completed',
                        'source' => 'Admin Action',
                        'order_id' => $order->id,
                        'type' => 'order_status',
                        'note' => 'Credit transaction (reseller profit) for order #' . $order->order_number,
                        'reseller_id' => $order->user_id,
                    ]);

                    // Update user balance
                    // if ($profitAmount > 0 && $order->user_id) {
                    //     User::where('id', $order->user_id)
                    //         ->increment('balance', $profitAmount);
                    // }

                    return $this->success('Settle reseller profit successfully', $order);
                } else {
                    return $this->failed('Order status is not completed', null, 400);
                }
            } else {
                return $this->failed('User is not admin', null, 403);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PATCH /orders/item/status/{id}
     * Body: status
     */
    public function updateOrderItemStatus(Request $request, $id)
    {
        try {
            $item = OrderItem::find($id);
            if (!$item) {
                return $this->failed('Order item not found', null, 404);
            }

            $validated = $request->validate([
                'status' => ['required', 'string', 'max:50'],
            ]);

            $item->status = $validated['status'];
            $item->save();

            return $this->success('Order item status updated successfully', $item);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /orders/status-summary
     * Returns all order statuses with their respective order counts.
     */
    public function orderStatusSummary()
    {
        try {
            $statuses = OrderStatus::withCount([
                'orders'
            ])->get();

            return $this->success('Order status summary fetched successfully', $statuses);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
