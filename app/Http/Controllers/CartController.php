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

    private function normalizePrice($value): ?float
    {
        return is_null($value) ? null : round((float) $value, 2);
    }

    private function resolveAdminPrice(Product $product, ?float $unitPrice): ?float
    {
        if (!is_null($product->admin_price)) {
            return $this->normalizePrice($product->admin_price);
        }

        return !is_null($unitPrice) ? (float) ceil($unitPrice * 1.05) : null;
    }

    private function calculateLineTotal(int $qty, ?float $resellerPrice): ?float
    {
        return !is_null($resellerPrice) ? round($qty * $resellerPrice, 2) : null;
    }

    private function calculateResellerProfit(int $qty, ?float $resellerPrice, ?float $adminPrice): ?float
    {
        return (!is_null($resellerPrice) && !is_null($adminPrice))
            ? round($qty * ($resellerPrice - $adminPrice), 2)
            : null;
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

            $this->recalculateCart($cart->id);
            $cart->load(['items.product.primaryImage',  'items.shop.district', 
            'items.product.productDiscount', 'items.productAttribute.attribute', 'items.productAttribute.value']);

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
                'reseller_price' => ['nullable', 'numeric', 'min:0'],
            ]);

            $product = Product::with('productDiscount')->find($validated['product_id']);
            if (!$product) {
                return $this->failed('Product not found', null, 404);
            }

            $unitPrice = $this->normalizePrice($product->unit_price);
            $adminPrice = $this->resolveAdminPrice($product, $unitPrice);
            $resellerPrice = $this->normalizePrice($request->input('reseller_price'));
            $resellerPrice = !is_null($resellerPrice) ? $resellerPrice : $adminPrice;

            if (!is_null($resellerPrice) && !is_null($adminPrice) && $resellerPrice < $adminPrice) {
                return $this->failed('Reseller price can not be less than admin price', [
                    'admin_price' => $adminPrice,
                    'reseller_price' => $resellerPrice,
                ], 422);
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
                ->where('attribute_id', $request->input('attribute_id'))
                ->first();

            if ($item) {
                $newQty = ((int) $item->qty) + (int) $validated['qty'];
                $item->qty = $newQty;
                $item->unit_price = $unitPrice;
                $item->admin_price = $adminPrice;
                $item->reseller_price = $resellerPrice;
                $item->line_total = $this->calculateLineTotal($newQty, $resellerPrice);
                $item->line_total_reseller_profit = $this->calculateResellerProfit($newQty, $resellerPrice, $adminPrice);
                $item->status = $item->status ?? 'active';
                $item->save();
            } else {
                $item = CartItem::create([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'attribute_id' => $request->input('attribute_id'),
                    'shop_id' => $product->vendor_id ?? null,
                    'qty' => (int) $validated['qty'],
                    'unit_price' => $unitPrice,
                    'admin_price' => $adminPrice,
                    'reseller_price' => $resellerPrice,
                    'line_total' => $this->calculateLineTotal((int) $validated['qty'], $resellerPrice),
                    'line_total_reseller_profit' => $this->calculateResellerProfit((int) $validated['qty'], $resellerPrice, $adminPrice),
                    'status' => 'active',
                    'note' => $request->input('note') ?? null,
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
            $adminPrice = $this->normalizePrice($item->admin_price ?? $item->unit_price);
            $resellerPrice = $this->normalizePrice($item->reseller_price);
            $item->line_total = $this->calculateLineTotal((int) $validated['qty'], $resellerPrice);
            $item->line_total_reseller_profit = $this->calculateResellerProfit((int) $validated['qty'], $resellerPrice, $adminPrice);
       
          
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
     * Add or update note on a cart item
     * PATCH /carts/items/{itemId}/note
     * Body: note
     */
    public function addNoteInCartItem(Request $request, $itemId)
    {
        try {
            $validated = $request->validate([
                'note' => ['nullable', 'string', 'max:500'],
            ]);

            $item = CartItem::find($itemId);
            if (!$item) {
                return $this->failed('Cart item not found', null, 404);
            }

            $item->note = $validated['note'] ?? null;
            $item->save();

            return $this->success('Note updated successfully', $item);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
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
        $items = CartItem::with('product')->where('cart_id', $cartId)->get();

        $totalItems = 0;
        $subtotal = 0;
        $resellerProfitTotal = 0;

        foreach ($items as $item) {
            $qty = (int) ($item->qty ?? 0);
            $unitPrice = $this->normalizePrice($item->unit_price ?? $item->product?->unit_price);
            $adminPrice = $this->normalizePrice($item->admin_price ?? $item->product?->admin_price);
            $adminPrice = !is_null($adminPrice)
                ? $adminPrice
                : (!is_null($unitPrice) ? (float) ceil($unitPrice * 1.05) : null);
            $resellerPrice = $this->normalizePrice($item->reseller_price ?? $adminPrice);

            if (!is_null($resellerPrice) && !is_null($adminPrice) && $resellerPrice < $adminPrice) {
                $resellerPrice = $adminPrice;
            }

            $line = (float) ($this->calculateLineTotal($qty, $resellerPrice) ?? 0);
            $resellerProfit = (float) ($this->calculateResellerProfit($qty, $resellerPrice, $adminPrice) ?? 0);

            if (
                $item->unit_price !== $unitPrice
                || $item->admin_price !== $adminPrice
                || $item->reseller_price !== $resellerPrice
                || $item->line_total !== $line
                || $item->line_total_reseller_profit !== $resellerProfit
            ) {
                $item->unit_price = $unitPrice;
                $item->admin_price = $adminPrice;
                $item->reseller_price = $resellerPrice;
                $item->line_total = $line;
                $item->line_total_reseller_profit = $resellerProfit;
                $item->save();
            }

            $totalItems += $qty;
            $subtotal += $line;
            $resellerProfitTotal += $resellerProfit;
        }

        $cart = Cart::find($cartId);
        if ($cart) {
            $cart->total_items = $totalItems;
            $cart->subtotal = round($subtotal, 2);
            $cart->reseller_profit_total = round($resellerProfitTotal, 2);
            $cart->save();
        }
    }
}
