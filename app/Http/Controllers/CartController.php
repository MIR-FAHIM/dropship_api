<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
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
     * Get active cart for a user, or create one
     * GET /carts/active/{userId}
     */
    public function getActiveCart($userId)
    {
        try {
            $cart = Cart::where('user_id', $userId)
                ->where('status', 'active')
                ->latest()
                ->first();

            if (!$cart) {
                $cart = Cart::create([
                    'user_id' => $userId,
                    'status' => 'active',
                    'total_items' => 0,
                    'subtotal' => 0,
                ]);
            }

            $cart->load(['items.product.primaryImage',  'items.shop']);

            return $this->success('Active cart fetched successfully', $cart);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Add item to cart (merge qty if product already exists in cart)
     * POST /carts/items/add
     * Body: user_id, product_id, qty
     */
    public function addItemToCart(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => ['required', 'integer', 'exists:users,id'],
                'product_id' => ['required', 'integer', 'exists:products,id'],
                'qty' => ['required', 'integer', 'min:1'],
            ]);

            $product = Product::with('productDiscount')->find($validated['product_id']);
            if (!$product) {
                return $this->failed('Product not found', null, 404);
            }

            // Decide the unit price snapshot
            $unitPrice = null;
            if (!is_null($product->unit_price) && $product->unit_price > 0) {
                $unitPrice = (float) $product->unit_price;
            } else {
                $unitPrice = !is_null($product->unit_price) ? (float) $product->unit_price : null;
            }

            // Apply product-level discount if available and valid
            if (!is_null($unitPrice) && $product->productDiscount && $product->productDiscount->isValid()) {
                $unitPrice = (float) $product->productDiscount->applyDiscount($unitPrice);
            }

            DB::beginTransaction();

            $cart = Cart::where('user_id', $validated['user_id'])
                ->where('status', 'active')
                ->latest()
                ->first();

            if (!$cart) {
                $cart = Cart::create([
                    'user_id' => $validated['user_id'],
                    'status' => 'active',
                    'total_items' => 0,
                    'subtotal' => 0,
                ]);
            }

            // Merge: same cart + same product = increment qty
            $item = CartItem::where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->first();

            if ($item) {
                $newQty = ((int) $item->qty) + (int) $validated['qty'];
                $item->qty = $newQty;
                $item->unit_price = $unitPrice;
                $item->line_total = ($unitPrice !== null) ? round($newQty * $unitPrice, 2) : null;
                $item->status = $item->status ?? 'active';
                $item->save();
            } else {
                $item = CartItem::create([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'shop_id' => $product->shop_id ?? null,
                    'qty' => (int) $validated['qty'],
                    'unit_price' => $unitPrice,
                    'line_total' => ($unitPrice !== null) ? round(((int) $validated['qty']) * $unitPrice, 2) : null,
                    'status' => 'active',
                ]);
            }

            $this->recalculateCart($cart->id);

            DB::commit();

            $cart = Cart::with(['items.product', 'items.shop'])->find($cart->id);

            return $this->success('Item added to cart successfully', [
                'cart' => $cart,
                'item' => $item
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update cart item qty
     * PUT /carts/items/update/{itemId}
     * Body: qty
     */
    public function updateCartItemQty(Request $request, $itemId)
    {
        try {
            $validated = $request->validate([
                'qty' => ['required', 'integer', 'min:0'],
            ]);

            $item = CartItem::find($itemId);
            if (!$item) {
                return $this->failed('Cart item not found', null, 404);
            }

            DB::beginTransaction();

            if ((int) $validated['qty'] === 0) {
                $cartId = $item->cart_id;
                $item->delete();
                $this->recalculateCart($cartId);

                DB::commit();

                $cart = Cart::with(['items.product', 'items.shop'])->find($cartId);
                return $this->success('Item removed (qty=0) and cart updated', $cart);
            }

            $item->qty = (int) $validated['qty'];
            $item->line_total = ($item->unit_price !== null)
                ? round(((int) $validated['qty']) * (float) $item->unit_price, 2)
                : null;
            $item->save();

            $this->recalculateCart($item->cart_id);

            DB::commit();

            $cart = Cart::with(['items.product', 'items.shop'])->find($item->cart_id);
            return $this->success('Cart item updated successfully', $cart);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove cart item
     * DELETE /carts/items/delete/{itemId}
     */
    public function removeCartItem($itemId)
    {
        try {
            $item = CartItem::find($itemId);
            if (!$item) {
                return $this->failed('Cart item not found', null, 404);
            }

            DB::beginTransaction();

            $cartId = $item->cart_id;
            $item->delete();

            $this->recalculateCart($cartId);

            DB::commit();

            $cart = Cart::with(['items.product', 'items.shop'])->find($cartId);
            return $this->success('Cart item removed successfully', $cart);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Clear cart (delete all items)
     * DELETE /carts/clear/{userId}
     */
    public function clearCart($userId)
    {
        try {
            $cart = Cart::where('user_id', $userId)
                ->where('status', 'active')
                ->latest()
                ->first();

            if (!$cart) {
                return $this->failed('Active cart not found', null, 404);
            }

            DB::beginTransaction();

            CartItem::where('cart_id', $cart->id)->delete();
            $cart->total_items = 0;
            $cart->subtotal = 0;
            $cart->save();

            DB::commit();

            $cart->load(['items.product', 'items.shop']);

            return $this->success('Cart cleared successfully', $cart);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Internal helper: recalculate cart totals from cart_items
     */
    private function recalculateCart($cartId)
    {
        $items = CartItem::where('cart_id', $cartId)->get();

        $totalItems = 0;
        $subtotal = 0;

        foreach ($items as $item) {
            $qty = (int) ($item->qty ?? 0);
            $line = (float) ($item->line_total ?? 0);

            $totalItems += $qty;
            $subtotal += $line;
        }

        $cart = Cart::find($cartId);
        if ($cart) {
            $cart->total_items = $totalItems;
            $cart->subtotal = round($subtotal, 2);
            $cart->save();
        }
    }
}
