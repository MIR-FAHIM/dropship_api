<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
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
                return $this->failed('Active cart not found', null, 404);
            }

            $cartItems = CartItem::with(['product'])
                ->where('cart_id', $cart->id)
                ->get();

            if ($cartItems->count() === 0) {
                return $this->failed('Cart is empty', null, 409);
            }

            DB::beginTransaction();

            // Recalculate subtotal from cart_items (server truth)
            $subtotal = 0;
            foreach ($cartItems as $ci) {
                $subtotal += (float) ($ci->line_total ?? 0);
            }

            // For now: shipping_fee & discount are kept null (or 0) until you add those modules
            $shippingFee = 0;
            $discount = 0;
            $total = round(($subtotal + $shippingFee) - $discount, 2);

            // Generate an order_number that is human-friendly and unique
            $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(Str::random(6));

            $order = Order::create([
                'user_id' => $validated['user_id'],
                'order_number' => $orderNumber,

                'status' => 'pending',
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
                'shipping_fee' => $shippingFee,
                'discount' => $discount,
                'total' => $total,

                'note' => $validated['note'] ?? null,
            ]);

            foreach ($cartItems as $ci) {
                $product = $ci->product;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $ci->product_id,
                    'shop_id' => $ci->shop_id,

                    // Snapshot important product fields
                    'product_name' => $product ? ($product->name ?? null) : null,
                    'sku' => $product ? ($product->sku ?? null) : null,

                    // Snapshot cart-time pricing
                    'unit_price' => $ci->unit_price,
                    'qty' => $ci->qty,
                    'line_total' => $ci->line_total,

                    'status' => 'pending',
                ]);
            }

            // Mark cart as checked_out and clear items
            $cart->status = 'checked_out';
            $cart->save();

            CartItem::where('cart_id', $cart->id)->delete();

            DB::commit();

            $order->load(['items']);

            return $this->success('Checkout successful. Order created.', $order, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            DB::rollBack();
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

            $orders = Order::where('user_id', $userId)
                ->latest()
                ->paginate($perPage);

            return $this->success('Orders fetched successfully', $orders);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

public function allOrders(Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 20);

            $orders = Order::latest()
                ->paginate($perPage);

            return $this->success('Orders fetched successfully', $orders);
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
            $order = Order::with(['items'])->find($id);

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
                'status' => ['required', 'string', 'max:50'],
            ]);

            $order->status = $validated['status'];
            $order->save();

            return $this->success('Order status updated successfully', $order);
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
}
