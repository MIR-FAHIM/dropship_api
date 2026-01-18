<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WishList;
use Illuminate\Http\Response;

class WishListController extends Controller
{
    /**
     * Add a product to the user's wishlist (or re-activate existing)
     */
    public function addWishProduct(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'product_id' => 'required|integer|exists:products,id',
        ]);

        $wish = WishList::where('user_id', $data['user_id'])
            ->where('product_id', $data['product_id'])
            ->first();

        if ($wish) {
            if ($wish->status) {
                return response()->json([
                    'message' => 'Product already in wishlist',
                    'wishlist' => $wish->load('product')
                ], Response::HTTP_OK);
            }

            $wish->status = true;
            $wish->save();

            return response()->json([
                'message' => 'Product added to wishlist',
                'wishlist' => $wish->load('product')
            ], Response::HTTP_OK);
        }

        $wish = WishList::create($data);

        return response()->json([
            'message' => 'Product added to wishlist',
            'wishlist' => $wish->load('product')
        ], Response::HTTP_CREATED);
    }

    /**
     * Get active wishlist items for a user
     */
    public function getWishList($userId)
    {
        $items = WishList::where('user_id', $userId)
            ->where('status', 1)
            ->with('product', 'product.primaryImage', 'product.shop', 'product.category', 'product.subcategory',   'product.brand')
            ->get();

        return response()->json([
            'count' => $items->count(),
            'items' => $items
        ], Response::HTTP_OK);
    }

    /**
     * Remove (soft) a wishlist item by id
     */
    public function deleteWishedProduct($id)
    {
        $wish = WishList::find($id);

        if (! $wish) {
            return response()->json(['message' => 'Wishlist item not found.'], Response::HTTP_NOT_FOUND);
        }

        $wish->status = false;
        $wish->save();

        return response()->json(['message' => 'Wishlist item removed.'], Response::HTTP_OK);
    }
}
