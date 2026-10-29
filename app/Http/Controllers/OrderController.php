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
use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Service\NotificationService;
use App\Service\OrderAccountingService;
use App\Service\CarrybeeService;
use App\Service\CompanyTransactionService;
use App\Service\MuthobartaSmsService;

class OrderController extends Controller
{
    protected NotificationService $notificationService;
    protected OrderAccountingService $orderAccountingService;
    protected MuthobartaSmsService $smsService;
    protected CompanyTransactionService $companyTransactions;

    public function __construct(
        NotificationService $notificationService,
        OrderAccountingService $orderAccountingService,
        MuthobartaSmsService $smsService,
        CompanyTransactionService $companyTransactions
    ) {
        $this->notificationService = $notificationService;
        $this->orderAccountingService = $orderAccountingService;
        $this->smsService = $smsService;
        $this->companyTransactions = $companyTransactions;
    }
    private function maskSensitive(array $payload): array
    {
        unset($payload['password'], $payload['password_confirmation']);
        return $payload;
    }

    private function resolveCheckoutUser(Request $request): ?User
    {
        $userId = $request->input('user_id');

        if (!empty($userId)) {
            $user = User::find($userId);
            if ($user) {
                return $user;
            }
        }

        return $request->attributes->get('api_user') ?? $request->user();
    }

    private function storeOrderError(
        Request $request,
        string $message,
        ?User $user = null,
        string $level = 'error',
        ?\Throwable $exception = null,
        ?array $extraData = null
    ): void {
        try {
            $logMessage = $message;

            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                $logMessage .= ' | Errors: ' . json_encode($exception->errors(), JSON_UNESCAPED_UNICODE);
            } elseif ($exception) {
                $logMessage .= ' | ' . $exception->getMessage();
            }

            $requestPayload = $this->maskSensitive($request->all());
            if (!empty($extraData)) {
                $requestPayload['_error_context'] = $extraData;
            }

            OrderErrorLog::create([
                'user_id'      => $user?->id,
                'level'        => $level,
                'message'      => $logMessage,
                'file'         => $exception?->getFile(),
                'line'         => $exception?->getLine(),
                'url'          => $request->fullUrl(),
                'method'       => $request->method(),
                'ip_address'   => $request->ip(),
                'user_agent'   => $request->userAgent(),
                'request_data' => json_encode($requestPayload, JSON_UNESCAPED_UNICODE),
                'stack_trace'  => $exception?->getTraceAsString(),
                'created_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to write order error log: ' . $e->getMessage(), [
                'original_message' => $message,
                'url'              => $request->fullUrl(),
            ]);
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

    private function normalizePrice($value): ?float
    {
        return is_null($value) ? null : round((float) $value, 2);
    }

    private function resolveAdminPrice($item, ?float $unitPrice): ?float
    {
        $adminPrice = $item->admin_price ?? $item->product?->admin_price ?? null;

        if (!is_null($adminPrice)) {
            return $this->normalizePrice($adminPrice);
        }

        return !is_null($unitPrice) ? (float) ceil($unitPrice * 1.05) : null;
    }

    private function calculateItemResellerProfit($item): float
    {
        $qty = (int) ($item->qty ?? 0);
        $adminPrice = $this->normalizePrice($item->admin_price ?? $item->unit_price);
        $resellerPrice = $this->normalizePrice($item->reseller_price);

        if (is_null($adminPrice) || is_null($resellerPrice)) {
            return 0;
        }

        return round($qty * ($resellerPrice - $adminPrice), 2);
    }

    private function calculateItemCompanyProductCommission($item): float
    {
        $qty = (int) ($item->qty ?? 0);
        $unitPrice = $this->normalizePrice($item->unit_price);
        $adminPrice = $this->normalizePrice($item->admin_price ?? $item->unit_price);

        if (is_null($unitPrice) || is_null($adminPrice)) {
            return 0;
        }

        return round($qty * ($adminPrice - $unitPrice), 2);
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

        $resellerProfit = round($order->items->sum(function ($item) {
            return $this->calculateItemResellerProfit($item);
        }), 2);

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

        $shippingSettlement = OrderSettlement::updateOrCreate(
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

        $this->companyTransactions->recordSettlement($shippingSettlement);

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

        $companyProductCommission = round($order->items->sum(function ($item) {
            return $this->calculateItemCompanyProductCommission($item);
        }), 2);

        OrderSettlement::updateOrCreate(
            [
                'order_id' => $order->id,
                'settlement_type' => OrderSettlement::TYPE_COMPANY_PRODUCT_COMMISSION,
                'payable_user_id' => null,
            ],
            [
                'vendor_id' => null,
                'user_type' => 'company',
                'settleable_amount' => $companyProductCommission,
                'currency' => 'BDT',
                'status' => OrderSettlement::STATUS_PENDING,
                'admin_note' => 'Company product commission earning for order #' . $order->order_number . ' (admin_price - unit_price)',
                'trx_id' => 'SET-' . $order->id . '-COM-COM',
                'created_by' => $createdBy,
            ]
        );
    }

    /**
     * POST /orders/checkout
     * Body: user_id, customer_name, customer_phone, shipping_address, zone, district, area, lat, lon, note
     *
     * Converts ACTIVE cart -> order + order_items in ONE DB transaction
     */
    public function checkout(Request $request)
    {
        $transactionStarted = false;
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

                'delivery_charge' => ['required', 'numeric', 'min:0'],
                'note' => ['nullable', 'string'],
            ]);

            $cart = Cart::where('user_id', $validated['user_id'])
                ->where('status', 'active')
                ->latest()
                ->first();

            if (!$cart) {
                $this->storeOrderError($request, 'Checkout failed: active cart not found', $this->resolveCheckoutUser($request), 'warning');
                return $this->failed('Active cart not found', null, 404);
            }

            $cartItems = CartItem::with(['product'])
                ->where('cart_id', $cart->id)
                ->get();

            if ($cartItems->count() === 0) {
                $this->storeOrderError($request, 'Checkout failed: cart is empty', $this->resolveCheckoutUser($request), 'warning');
                return $this->failed('Cart is empty', null, 409);
            }

            $subtotal = 0;
            $resellerProfit = 0;
            $cartItemPricing = [];

            foreach ($cartItems as $ci) {
                $qty = (int) ($ci->qty ?? 0);
                $unitPrice = $this->normalizePrice($ci->unit_price ?? $ci->product?->unit_price);
                $adminPrice = $this->resolveAdminPrice($ci, $unitPrice);
                $resellerPrice = $this->normalizePrice($ci->reseller_price ?? $adminPrice);

                if (!is_null($adminPrice) && !is_null($resellerPrice) && $resellerPrice < $adminPrice) {
                    $errorData = [
                        'cart_item_id'   => $ci->id,
                        'product_id'     => $ci->product_id,
                        'admin_price'    => $adminPrice,
                        'reseller_price' => $resellerPrice,
                    ];
                    $this->storeOrderError(
                        $request,
                        'Checkout failed: Cart item reseller price cannot be less than admin price',
                        $this->resolveCheckoutUser($request),
                        'warning',
                        null,
                        $errorData
                    );
                    return $this->failed('Cart item reseller price can not be less than admin price', $errorData, 422);
                }

                $lineTotal = !is_null($resellerPrice) ? round($qty * $resellerPrice, 2) : 0;
                $lineResellerProfit = (!is_null($resellerPrice) && !is_null($adminPrice))
                    ? round($qty * ($resellerPrice - $adminPrice), 2)
                    : 0;

                $subtotal += $lineTotal;
                $resellerProfit += $lineResellerProfit;
                $cartItemPricing[$ci->id] = [
                    'unit_price' => $unitPrice,
                    'admin_price' => $adminPrice,
                    'reseller_price' => $resellerPrice,
                    'line_total' => $lineTotal,
                    'line_total_reseller_profit' => $lineResellerProfit,
                ];
            }

            DB::beginTransaction();
            $transactionStarted = true;

            // For now: shipping_fee & discount are kept null (or 0) until you add those modules
            $shippingFee = $request->input('delivery_charge', 0);
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
                'reseller_price' => round($subtotal, 2),
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
                $pricing = $cartItemPricing[$ci->id];

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $ci->product_id,
                    'shop_id' => $ci->shop_id,
                    'attribute_id' => $ci->attribute_id,

                    // Snapshot important product fields
                    'product_name' => $product ? ($product->name ?? null) : null,
                    'sku' => $product ? ($product->sku ?? null) : null,

                    // Snapshot cart-time pricing
                    'unit_price' => $pricing['unit_price'],
                    'admin_price' => $pricing['admin_price'],
                    'reseller_price' => $pricing['reseller_price'],
                    'qty' => $ci->qty,
                    'line_total' => $pricing['line_total'],
                    'line_total_reseller_profit' => $pricing['line_total_reseller_profit'],

                    'status' => 1,
                    'note' => $ci->note ?? null,
                ]);
            }

            // Mark cart as checked_out and clear items
            $cart->status = 'checked_out';
            $cart->save();

            // Check advance eligibility (< 5 completed orders rule)
            $eligibility = $this->orderAccountingService->checkAdvanceEligibility($order->user_id);
            $payFromWallet = filter_var($request->input('pay_from_wallet', false), FILTER_VALIDATE_BOOLEAN);
            $advanceAmountInput = (float) $request->input('advance_amount', 0);
            
            // Advance delivery charge reflects actual calculated delivery charge ($shippingFee)
            if ($eligibility['requires_advance']) {
                $advanceAmount = $advanceAmountInput > 0 ? $advanceAmountInput : (float) $shippingFee;
            } else {
                $advanceAmount = $advanceAmountInput > 0 ? $advanceAmountInput : 0.00;
            }

            if ($eligibility['requires_advance'] || $advanceAmountInput > 0) {
                if ($payFromWallet) {
                    $this->orderAccountingService->payAdvanceFromWallet($order, $advanceAmount);
                } elseif ($request->filled('advance_trx_id')) {
                    $this->orderAccountingService->submitMfsAdvance(
                        $order,
                        $advanceAmount,
                        $request->input('advance_payment_method', 'bkash'),
                        $request->input('advance_trx_id'),
                        $request->input('advance_sender_phone')
                    );
                } else {
                    // Pay later option: Order created with advance_status = 'pending'
                    $this->orderAccountingService->processOrderBooking($order, [
                        'amount' => $advanceAmount,
                        'status' => 'pending',
                        'recipient' => 'platform',
                        'payment_method' => $request->input('advance_payment_method', null),
                        'trx_id' => null,
                        'sender_phone' => null,
                    ]);
                }
            } else {
                // No advance required
                $this->orderAccountingService->processOrderBooking($order, [
                    'amount' => 0.00,
                    'status' => 'none',
                    'recipient' => 'none',
                ]);
            }

            DB::commit();
            $transactionStarted = false;

            $order->load(['items', 'vendor.user']);
            $formattedTotal = number_format((float) $order->total, 2, '.', '');

            $this->notificationService->createNotificationSafely([
                'title'      => 'New Order Created',
                'subtitle'   => "Order {$order->order_number} created. Total {$formattedTotal} BDT.",
                'created_by' => $order->user_id,
                'send_to'    => $order->user_id,
                'is_seen'    => false,
                'type'       => 'order',
                'is_active'  => true,
                'image'      => null,
                'module'     => 'order',
            ]);

            if ($order->vendor?->user_id) {
                $this->notificationService->createNotificationSafely([
                    'title'      => 'New Vendor Order',
                    'subtitle'   => "Order {$order->order_number} includes product(s) from your shop.",
                    'created_by' => $order->user_id,
                    'send_to'    => $order->vendor->user_id,
                    'is_seen'    => false,
                    'type'       => 'order',
                    'is_active'  => true,
                    'image'      => null,
                    'module'     => 'order',
                ]);
            }

            $this->notificationService->createAdminNotifications([
                'title'      => 'New Checkout Order',
                'subtitle'   => "Order {$order->order_number} placed. Total {$formattedTotal} BDT.",
                'created_by' => $order->user_id,
                'is_seen'    => false,
                'type'       => 'order',
                'is_active'  => true,
                'image'      => null,
                'module'     => 'order',
            ]);

            $adminNumber = config('services.muthobarta.admin_number', '01941606310');
            if ($adminNumber) {
                $this->smsService->send(
                    $adminNumber,
                    "New order {$order->order_number} placed. Total {$order->total} BDT.",
                    'order_checkout'
                );
            }

            return $this->success('Checkout successful. Order created.', $order, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($transactionStarted && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->storeOrderError($request, 'Checkout validation failed', $this->resolveCheckoutUser($request), 'warning', $e);
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            if ($transactionStarted && DB::transactionLevel() > 0) {
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
        DB::beginTransaction();
        try {
            /** @var Order|null $order */
            $order = Order::with(['items', 'deliveryInformation'])->where('id', $id)->lockForUpdate()->first();
            if (!$order) {
                DB::rollBack();
                return $this->failed('Order not found', null, 404);
            }

            // Lock order: reject update if consignment has already been created/linked
            if ($order->hasConsignment()) {
                DB::rollBack();
                return $this->failed(
                    'This order cannot be edited because its delivery consignment has already been created.',
                    [
                        'order_id' => $order->id,
                        'consignment_id' => $order->getConsignmentId(),
                        'locked' => true,
                    ],
                    422
                );
            }

            $validated = $request->validate([
                // Customer & Delivery Information
                'customer_name'    => ['nullable', 'string', 'max:255'],
                'customer_phone'   => ['nullable', 'string', 'max:50'],
                'shipping_address' => ['nullable', 'string', 'max:1000'],
                'zone'             => ['nullable', 'string', 'max:100'],
                'district'         => ['nullable', 'string', 'max:100'],
                'area'             => ['nullable', 'string', 'max:100'],
                'lat'              => ['nullable', 'numeric'],
                'lon'              => ['nullable', 'numeric'],
                'note'             => ['nullable', 'string'],

                // Financials & Charges
                'shipping_fee'     => ['nullable', 'numeric', 'min:0'],
                'delivery_charge'  => ['nullable', 'numeric', 'min:0'],
                'discount'         => ['nullable', 'numeric', 'min:0'],
                'subtotal'         => ['nullable', 'numeric', 'min:0'],
                'total'            => ['nullable', 'numeric', 'min:0'],

                // Payment Information
                'payment_status'   => ['nullable', 'string', 'max:50'],
                'advance_amount'   => ['nullable', 'numeric', 'min:0'],
                'advance_payment_method' => ['nullable', 'string', 'max:50'],
                'advance_trx_id'   => ['nullable', 'string', 'max:100'],
                'advance_sender_phone' => ['nullable', 'string', 'max:50'],
                'advance_status'   => ['nullable', 'string', 'in:none,pending,approved,rejected'],
                'paid_amount'      => ['nullable', 'numeric', 'min:0'],
                'due_amount'       => ['nullable', 'numeric', 'min:0'],

                // Line items
                'items'            => ['nullable', 'array', 'min:1'],
                'items.*.id'       => ['nullable', 'integer'],
                'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
                'items.*.qty'      => ['required_with:items', 'integer', 'min:1'],
                'items.*.reseller_price' => ['required_with:items', 'numeric', 'min:0'],
                'items.*.admin_price' => ['nullable', 'numeric', 'min:0'],
                'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
                'items.*.product_name' => ['nullable', 'string', 'max:255'],
                'items.*.sku'      => ['nullable', 'string', 'max:100'],
                'items.*.attribute_id' => ['nullable', 'integer'],
                'items.*.shop_id'  => ['nullable', 'integer'],
                'items.*.note'     => ['nullable', 'string'],
            ]);

            // Process order items
            if ($request->has('items') && is_array($validated['items'])) {
                $processedItemIds = [];
                $subtotal = 0.0;
                $resellerProfit = 0.0;

                foreach ($validated['items'] as $itemData) {
                    $existingItem = !empty($itemData['id'])
                        ? $order->items->firstWhere('id', (int) $itemData['id'])
                        : null;

                    $productId = $itemData['product_id'] ?? $existingItem?->product_id;
                    $product = $productId ? Product::find($productId) : null;

                    $qty = (int) $itemData['qty'];
                    if ($qty < 1) {
                        DB::rollBack();
                        return $this->failed('Item quantity must be at least 1.', null, 422);
                    }

                    $resellerPrice = (float) $itemData['reseller_price'];

                    $adminPrice = isset($itemData['admin_price'])
                        ? (float) $itemData['admin_price']
                        : (float) ($product?->admin_price ?? $existingItem?->admin_price ?? 0);

                    $unitPrice = isset($itemData['unit_price'])
                        ? (float) $itemData['unit_price']
                        : (float) ($product?->unit_price ?? $existingItem?->unit_price ?? 0);

                    if ($resellerPrice < $adminPrice) {
                        DB::rollBack();
                        $pName = $itemData['product_name'] ?? $product?->name ?? 'item';
                        return $this->failed(
                            "Reseller selling price (৳{$resellerPrice}) cannot be less than admin base price (৳{$adminPrice}) for '{$pName}'.",
                            ['product_id' => $productId],
                            422
                        );
                    }

                    $attributeId = array_key_exists('attribute_id', $itemData)
                        ? ($itemData['attribute_id'] ? (int) $itemData['attribute_id'] : null)
                        : $existingItem?->attribute_id;

                    if ($attributeId && $productId) {
                        $attrExists = ProductAttribute::where('id', $attributeId)
                            ->where('product_id', $productId)
                            ->exists();
                        if (!$attrExists) {
                            DB::rollBack();
                            return $this->failed("Selected product attribute does not belong to this product.", ['attribute_id' => $attributeId], 422);
                        }
                    }

                    $lineTotal = round($qty * $resellerPrice, 2);
                    $lineResellerProfit = round($qty * ($resellerPrice - $adminPrice), 2);

                    $subtotal += $lineTotal;
                    $resellerProfit += $lineResellerProfit;

                    $itemAttributes = [
                        'order_id' => $order->id,
                        'product_id' => $productId,
                        'shop_id' => $itemData['shop_id'] ?? $product?->shop_id ?? $existingItem?->shop_id ?? $order->vendor_id,
                        'product_name' => $itemData['product_name'] ?? $product?->name ?? $existingItem?->product_name,
                        'sku' => $itemData['sku'] ?? $product?->sku ?? $existingItem?->sku,
                        'attribute_id' => $attributeId,
                        'unit_price' => $unitPrice,
                        'admin_price' => $adminPrice,
                        'reseller_price' => $resellerPrice,
                        'qty' => $qty,
                        'line_total' => $lineTotal,
                        'line_total_reseller_profit' => $lineResellerProfit,
                        'note' => $itemData['note'] ?? $existingItem?->note ?? null,
                    ];

                    if ($existingItem) {
                        $existingItem->update($itemAttributes);
                        $processedItemIds[] = $existingItem->id;
                    } else {
                        $newItem = OrderItem::create(array_merge($itemAttributes, ['status' => 1]));
                        $processedItemIds[] = $newItem->id;
                    }
                }

                // Delete items removed from the order
                foreach ($order->items as $oldItem) {
                    if (!in_array($oldItem->id, $processedItemIds)) {
                        $oldItem->delete();
                    }
                }
            } else {
                $subtotal = (float) $order->items->sum('line_total');
                $resellerProfit = (float) $order->items->sum('line_total_reseller_profit');
            }

            // Delivery charge & Discount
            $shippingFee = isset($validated['shipping_fee'])
                ? (float) $validated['shipping_fee']
                : (isset($validated['delivery_charge']) ? (float) $validated['delivery_charge'] : (float) ($order->shipping_fee ?? 0));

            $discount = isset($validated['discount'])
                ? (float) $validated['discount']
                : (float) ($order->discount ?? 0);

            if ($discount > ($subtotal + $shippingFee)) {
                DB::rollBack();
                return $this->failed('Discount cannot exceed the subtotal plus shipping fee.', null, 422);
            }

            // Authoritative total recalculation
            $recalculatedTotal = round(($subtotal + $shippingFee) - $discount, 2);

            // Validate client-submitted total if provided
            if (isset($validated['total'])) {
                $submittedTotal = (float) $validated['total'];
                if (abs($submittedTotal - $recalculatedTotal) > 0.05) {
                    DB::rollBack();
                    return $this->failed(
                        "Total order amount mismatch: submitted ৳{$submittedTotal}, but recalculated ৳{$recalculatedTotal} (৳{$subtotal} subtotal + ৳{$shippingFee} shipping - ৳{$discount} discount).",
                        [
                            'submitted_total' => $submittedTotal,
                            'recalculated_total' => $recalculatedTotal,
                            'subtotal' => $subtotal,
                            'shipping_fee' => $shippingFee,
                            'discount' => $discount,
                        ],
                        422
                    );
                }
            }

            // Advance & Remaining COD
            $advanceAmount = isset($validated['advance_amount'])
                ? (float) $validated['advance_amount']
                : (float) ($order->advance_amount ?? 0);

            if ($advanceAmount > $recalculatedTotal) {
                DB::rollBack();
                return $this->failed("Advance amount (৳{$advanceAmount}) cannot exceed total order amount (৳{$recalculatedTotal}).", null, 422);
            }

            $remainingCod = max(0, round($recalculatedTotal - $advanceAmount, 2));

            // Validate paid and due amounts if submitted
            if (isset($validated['paid_amount'])) {
                $paid = (float) $validated['paid_amount'];
                if ($paid > $recalculatedTotal) {
                    DB::rollBack();
                    return $this->failed("Paid amount cannot exceed total order amount.", null, 422);
                }
            }
            if (isset($validated['due_amount'])) {
                $due = (float) $validated['due_amount'];
                if ($due < 0 || $due > $recalculatedTotal) {
                    DB::rollBack();
                    return $this->failed("Due amount is invalid.", null, 422);
                }
            }

            // Assemble order update payload
            $orderPayload = [
                'subtotal' => round($subtotal, 2),
                'reseller_price' => round($subtotal, 2),
                'reseller_profit' => round($resellerProfit, 2),
                'shipping_fee' => $shippingFee,
                'discount' => $discount,
                'total' => $recalculatedTotal,
                'remaining_cod' => $remainingCod,
            ];

            foreach (['customer_name', 'customer_phone', 'shipping_address', 'zone', 'district', 'area', 'lat', 'lon', 'note'] as $k) {
                if (array_key_exists($k, $validated)) {
                    $orderPayload[$k] = $validated[$k];
                }
            }

            foreach (['payment_status', 'advance_amount', 'advance_payment_method', 'advance_trx_id', 'advance_sender_phone', 'advance_status'] as $k) {
                if (array_key_exists($k, $validated)) {
                    $orderPayload[$k] = $validated[$k];
                }
            }

            $order->update($orderPayload);

            DB::commit();

            $order->load([
                'items.shop',
                'items.product.productAttributes.attribute',
                'items.product.productAttributes.value',
                'items.productAttribute.attribute',
                'items.productAttribute.value',
                'deliveryInformation',
            ]);

            return $this->success('Order updated successfully', $order);
        } catch (\Illuminate\Validation\ValidationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
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

            $query = Order::with(['status', 'user', 'vendor', 'items.shop', 'deliveryInformation']);

            // Vendor filter: support vendor_id or vendor
            $vendorId = $request->get('vendor_id') ?? $request->get('vendor');
            if (!empty($vendorId) && $vendorId !== 'all') {
                $vendorId = (int) $vendorId;
                $query->where(function ($q) use ($vendorId) {
                    $q->where('vendor_id', $vendorId)
                      ->orWhereHas('items', function ($itemQuery) use ($vendorId) {
                          $itemQuery->where('shop_id', $vendorId);
                      });
                });
            }

            // Status filter
            if ($request->filled('status') && $request->get('status') !== 'all') {
                $query->where('status', $request->get('status'));
            }

            // Search filter across order_number, customer, and dropshipper
            if ($request->filled('search')) {
                $search = trim($request->get('search'));
                if ($search !== '') {
                    $query->where(function ($q) use ($search) {
                        $q->where('order_number', 'like', "%{$search}%")
                          ->orWhere('customer_name', 'like', "%{$search}%")
                          ->orWhere('customer_phone', 'like', "%{$search}%")
                          ->orWhereHas('user', function ($uq) use ($search) {
                              $uq->where('name', 'like', "%{$search}%")
                                 ->orWhere('phone', 'like', "%{$search}%")
                                 ->orWhere('email', 'like', "%{$search}%");
                          });
                    });
                }
            }

            $orders = $query->latest()->paginate($perPage);

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
                'items.product.productAttributes.attribute',
                'items.product.productAttributes.value',
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

            $order->has_consignment = $order->hasConsignment();
            $order->is_editable = $order->isEditable();
            $order->consignment_id = $order->getConsignmentId();

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

            $previousStatusId = (int) $order->status;
            $previousPaymentStatus = $order->payment_status;
            $statusId = (int) $validated['status_id'];
            $statusName = OrderStatus::where('id', $statusId)->value('name') ?: 'Status ' . $statusId;

            $order->status = $statusId;
            $order->save();

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status_id' => $statusId,
                'note' => $validated['note'] ?? null,
                'changed_by' => null,
            ]);

            if ($previousStatusId !== $statusId) {
                $order->loadMissing('vendor.user');

                $this->notificationService->createNotificationSafely([
                    'title'      => 'Order Status Updated',
                    'subtitle'   => "Order {$order->order_number} status changed to {$statusName}.",
                    'created_by' => $request->input('changed_by'),
                    'send_to'    => $order->user_id,
                    'is_seen'    => false,
                    'type'       => 'order',
                    'is_active'  => true,
                    'image'      => null,
                    'module'     => 'order',
                ]);

                if ($order->vendor?->user_id) {
                    $this->notificationService->createNotificationSafely([
                        'title'      => 'Vendor Order Status Updated',
                        'subtitle'   => "Order {$order->order_number} status changed to {$statusName}.",
                        'created_by' => $request->input('changed_by'),
                        'send_to'    => $order->vendor->user_id,
                        'is_seen'    => false,
                        'type'       => 'order',
                        'is_active'  => true,
                        'image'      => null,
                        'module'     => 'order',
                    ]);
                }

                $this->notificationService->createAdminNotifications([
                    'title'      => 'Order Status Updated',
                    'subtitle'   => "Order {$order->order_number} status changed to {$statusName}.",
                    'created_by' => $request->input('changed_by'),
                    'is_seen'    => false,
                    'type'       => 'order',
                    'is_active'  => true,
                    'image'      => null,
                    'module'     => 'order',
                ]);
            }

            if (in_array($statusId, [6, 9])) {
                $order->payment_status = 'paid';
                $order->save();

                $this->orderAccountingService->processOrderDelivery($order);

                if ($previousPaymentStatus !== 'paid') {
                    $formattedTotal = number_format((float) $order->total, 2, '.', '');

                    $this->notificationService->createNotificationSafely([
                        'title'      => 'Order Payment Completed',
                        'subtitle'   => "Payment collected for order {$order->order_number}. Amount {$formattedTotal} BDT.",
                        'created_by' => $request->input('changed_by'),
                        'send_to'    => $order->user_id,
                        'is_seen'    => false,
                        'type'       => 'payment',
                        'is_active'  => true,
                        'image'      => null,
                        'module'     => 'order',
                    ]);

                    $this->notificationService->createAdminNotifications([
                        'title'      => 'Order Payment Completed',
                        'subtitle'   => "Payment collected for order {$order->order_number}. Amount {$formattedTotal} BDT.",
                        'created_by' => $request->input('changed_by'),
                        'is_seen'    => false,
                        'type'       => 'payment',
                        'is_active'  => true,
                        'image'      => null,
                        'module'     => 'order',
                    ]);
                }
            } elseif (in_array($statusId, [7, 8, 10, 11])) {
                $order->payment_status = 'unpaid';
                $order->courier_payout_status = 'pending';
                $order->save();

                $this->orderAccountingService->processOrderReversal($order, "Order status changed to {$statusName}");
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

                    $formattedProfit = number_format($profitAmount, 2, '.', '');
                    $this->notificationService->createNotificationSafely([
                        'title'      => 'Reseller Profit Settled',
                        'subtitle'   => "Profit for order {$order->order_number} settled. Amount {$formattedProfit} BDT.",
                        'created_by' => $request->changed_by,
                        'send_to'    => $order->user_id,
                        'is_seen'    => false,
                        'type'       => 'payment',
                        'is_active'  => true,
                        'image'      => null,
                        'module'     => 'order',
                    ]);

                    $this->notificationService->createAdminNotifications([
                        'title'      => 'Reseller Profit Settled',
                        'subtitle'   => "Profit for order {$order->order_number} settled. Amount {$formattedProfit} BDT.",
                        'created_by' => $request->changed_by,
                        'is_seen'    => false,
                        'type'       => 'payment',
                        'is_active'  => true,
                        'image'      => null,
                        'module'     => 'order',
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

    /**
     * GET /orders/check-advance-requirement
     * Checks if current dropshipper requires advance (< 5 orders) and available wallet balance.
     */
    public function checkAdvanceRequirement(Request $request)
    {
        try {
            $userId = (int) ($request->user()?->id ?: $request->input('user_id'));
            if (!$userId) {
                return $this->failed('User ID is required', null, 400);
            }

            $eligibility = $this->orderAccountingService->checkAdvanceEligibility($userId);
            $balances = $this->orderAccountingService->calculateUserBalances($userId);

            return $this->success('Advance eligibility fetched successfully', [
                'requires_advance' => $eligibility['requires_advance'],
                'completed_orders_count' => $eligibility['completed_orders_count'],
                'min_orders_threshold' => $eligibility['min_orders_threshold'],
                'default_advance_amount' => $eligibility['default_advance_amount'],
                'available_wallet_balance' => $balances['available_balance'],
                'can_pay_from_wallet' => $balances['available_balance'] >= $eligibility['default_advance_amount'],
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /orders/{id}/pay-advance
     * Pay or submit advance payment for an existing pending order.
     */
    public function payAdvance(Request $request, $id)
    {
        try {
            $order = Order::find($id);
            if (!$order) {
                return $this->failed('Order not found', null, 404);
            }

            if ($order->advance_status === 'approved') {
                return $this->failed('Advance payment for this order is already approved.', null, 400);
            }

            $payFromWallet = filter_var($request->input('pay_from_wallet', false), FILTER_VALIDATE_BOOLEAN);
            $amount = (float) $request->input('amount', 130.00);

            if ($amount <= 0) {
                return $this->failed('Advance amount must be greater than 0', null, 422);
            }

            if ($payFromWallet) {
                $this->orderAccountingService->payAdvanceFromWallet($order, $amount);
                $order->refresh();

                return $this->success('Advance payment deducted from wallet successfully.', [
                    'order' => $order,
                    'advance_status' => 'approved',
                ]);
            }

            $validated = $request->validate([
                'payment_method' => ['required', 'string', 'max:50'],
                'trx_id' => ['required', 'string', 'max:100'],
                'sender_phone' => ['nullable', 'string', 'max:30'],
            ]);

            $order = $this->orderAccountingService->submitMfsAdvance(
                $order,
                $amount,
                $validated['payment_method'],
                $validated['trx_id'],
                $validated['sender_phone'] ?? null
            );

            // Notify Admin of new advance payment submitted
            $this->notificationService->createAdminNotifications([
                'title'      => 'Order Advance Payment Submitted',
                'subtitle'   => "Order {$order->order_number} advance of {$amount} BDT submitted via {$validated['payment_method']} (Trx: {$validated['trx_id']}).",
                'created_by' => $order->user_id,
                'is_seen'    => false,
                'type'       => 'payment',
                'is_active'  => true,
                'image'      => null,
                'module'     => 'order',
            ]);

            return $this->success('Advance payment submitted successfully. Awaiting admin verification.', [
                'order' => $order,
                'advance_status' => 'pending',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /orders/{id}/approve-advance
     * Admin Quick-Approve order advance.
     */
    public function approveAdvance(Request $request, $id)
    {
        try {
            $order = Order::find($id);
            if (!$order) {
                return $this->failed('Order not found', null, 404);
            }

            if ($order->advance_status === 'approved') {
                return $this->failed('Advance payment is already approved.', null, 400);
            }

            $adminId = (int) ($request->user()?->id ?: 1);
            $note = $request->input('admin_note', 'Approved by Admin');

            $order = $this->orderAccountingService->approveOrderAdvance($order, $adminId, $note);

            // Notify dropshipper
            $this->notificationService->createNotificationSafely([
                'title'      => 'Advance Payment Approved',
                'subtitle'   => "Advance payment for Order {$order->order_number} has been verified and approved.",
                'created_by' => $adminId,
                'send_to'    => $order->user_id,
                'is_seen'    => false,
                'type'       => 'payment',
                'is_active'  => true,
                'image'      => null,
                'module'     => 'order',
            ]);

            return $this->success('Order advance approved successfully', $order);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /orders/{id}/reject-advance
     * Admin Reject order advance.
     */
    public function rejectAdvance(Request $request, $id)
    {
        try {
            $order = Order::find($id);
            if (!$order) {
                return $this->failed('Order not found', null, 404);
            }

            $validated = $request->validate([
                'reason' => ['required', 'string', 'max:255'],
            ]);

            $adminId = (int) ($request->user()?->id ?: 1);
            $order = $this->orderAccountingService->rejectOrderAdvance($order, $adminId, $validated['reason']);

            // Notify dropshipper
            $this->notificationService->createNotificationSafely([
                'title'      => 'Advance Payment Rejected',
                'subtitle'   => "Advance payment for Order {$order->order_number} was rejected: {$validated['reason']}. Please submit a valid payment.",
                'created_by' => $adminId,
                'send_to'    => $order->user_id,
                'is_seen'    => false,
                'type'       => 'payment',
                'is_active'  => true,
                'image'      => null,
                'module'     => 'order',
            ]);

            return $this->success('Order advance rejected successfully', $order);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
