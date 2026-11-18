<?php

namespace App\Service;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVendorChange;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Moves a product to another vendor, and everything that follows the product's vendor:
 *
 *  - products.vendor_id
 *  - products.sku, when it is the auto SKU "p{id}v{vendor}" (custom SKUs are kept)
 *  - cart_items.shop_id of carts not checked out yet — checkout uses the cart's shop_id,
 *    so a stale cart would still send the next order to the old vendor
 *
 * Kept as they are, on purpose:
 *  - order_items.shop_id / orders.vendor_id of existing orders: the old vendor received
 *    and handled them, and settlements/invoices are built from them. Open ones are counted
 *    in the preview so the admin can reassign them from the order if needed.
 *  - images, attributes, discounts, reviews, wishlists, clicks: tied to the product, not the vendor.
 *
 * Every change is logged in product_vendor_changes.
 */
class ProductVendorChangeService
{
    /** Order statuses still being worked on (not delivered/closed). */
    private const OPEN_ORDER_STATUSES = [1, 2, 3, 4, 5, 12];

    /** What a change to $to would do, without changing anything. */
    public function preview(Product $product, Vendor $to): array
    {
        $from = $product->vendor_id ? Vendor::find($product->vendor_id) : null;

        return [
            'product_id' => $product->id,
            'from_vendor' => $from ? ['id' => $from->id, 'shop_name' => $from->shop_name] : null,
            'to_vendor' => ['id' => $to->id, 'shop_name' => $to->shop_name, 'is_active' => (bool) $to->is_active],
            'same_vendor' => (int) $product->vendor_id === (int) $to->id,
            'sku' => [
                'current' => $product->sku,
                'is_auto' => $this->isAutoSku($product),
                'after' => $this->isAutoSku($product) ? $this->autoSku($product, $to->id) : $product->sku,
            ],
            'cart_items_to_move' => $this->staleCartItems($product, $to->id)->count(),
            'open_orders' => $this->openOrdersCount($product),
            'all_orders' => $this->ordersCount($product),
        ];
    }

    /**
     * @param bool $updateSku change an auto SKU p{id}v{old} to p{id}v{new}
     */
    public function change(Product $product, Vendor $to, ?string $reason, ?int $adminId, bool $updateSku = true): array
    {
        if ((int) $product->vendor_id === (int) $to->id) {
            throw new \InvalidArgumentException('The product already belongs to this vendor.');
        }

        return DB::transaction(function () use ($product, $to, $reason, $adminId, $updateSku) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            $fromId = $product->vendor_id;
            $oldSku = $product->sku;

            $newSku = ($updateSku && $this->isAutoSku($product)) ? $this->autoSku($product, $to->id) : $oldSku;

            $product->vendor_id = $to->id;
            $product->sku = $newSku;
            $product->save();

            $cartItemsMoved = $this->staleCartItems($product, $to->id)->update(['shop_id' => $to->id]);
            $openOrders = $this->openOrdersCount($product, $fromId);

            $log = ProductVendorChange::create([
                'product_id' => $product->id,
                'from_vendor_id' => $fromId,
                'to_vendor_id' => $to->id,
                'old_sku' => $oldSku,
                'new_sku' => $newSku,
                'cart_items_moved' => $cartItemsMoved,
                'open_orders_left' => $openOrders,
                'reason' => $reason,
                'changed_by' => $adminId,
            ]);

            return [
                'product' => $product->fresh(['vendor', 'category', 'brand', 'primaryImage']),
                'change' => $log,
            ];
        });
    }

    /** Auto SKU made at create / copy: p{product id}v{vendor id}, maybe with a -copy-N suffix. */
    private function isAutoSku(Product $product): bool
    {
        return $product->sku === null || $product->sku === ''
            || (bool) preg_match('/^p' . $product->id . 'v\d+(-copy-\d+)?$/i', (string) $product->sku);
    }

    private function autoSku(Product $product, int $vendorId): string
    {
        $base = 'p' . $product->id . 'v' . $vendorId;
        $candidate = $base;
        $n = 1;
        while (Product::where('sku', $candidate)->where('id', '!=', $product->id)->exists()) {
            $candidate = $base . '-copy-' . $n++;
        }

        return $candidate;
    }

    /** Cart lines for this product that still point at another vendor, in carts not checked out yet. */
    private function staleCartItems(Product $product, int $vendorId)
    {
        $openCartIds = DB::table('carts')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'checked_out'))
            ->select('id');

        return CartItem::where('product_id', $product->id)
            ->whereIn('cart_id', $openCartIds)
            ->where(fn ($q) => $q->whereNull('shop_id')->orWhere('shop_id', '!=', $vendorId));
    }

    private function openOrdersCount(Product $product, ?int $vendorId = null): int
    {
        return $this->orderQuery($product, $vendorId)->whereIn('orders.status', self::OPEN_ORDER_STATUSES)->count('orders.id');
    }

    private function ordersCount(Product $product): int
    {
        return $this->orderQuery($product, null)->count('orders.id');
    }

    private function orderQuery(Product $product, ?int $vendorId)
    {
        return DB::table('orders')
            ->whereExists(function ($q) use ($product, $vendorId) {
                $q->select(DB::raw(1))->from('order_items')
                    ->whereColumn('order_items.order_id', 'orders.id')
                    ->where('order_items.product_id', $product->id);
                if ($vendorId !== null) {
                    $q->where('order_items.shop_id', $vendorId);
                }
            });
    }
}
