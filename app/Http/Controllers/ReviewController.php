<?php

namespace App\Http\Controllers;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReviewController extends Controller
{
    /**
     * Add a new review
     */
    public function addReview(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'product_id' => 'required|integer|exists:products,id',
            'comment' => 'required|string',
            'star_count' => 'required|integer|min:1|max:5',
            'status' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:0',
            'type' => 'nullable|string',
        ]);

        $review = Review::create([
            'user_id' => $data['user_id'],
            'product_id' => $data['product_id'],
            'comment' => $data['comment'],
            'star_count' => $data['star_count'],
            'status' => array_key_exists('status', $data) ? (bool) $data['status'] : true,
            'priority' => $data['priority'] ?? 0,
            'type' => $data['type'] ?? null,
        ]);

        return response()->json([
            'message' => 'Review added successfully',
            'review' => $review->load('user', 'product')
        ], Response::HTTP_CREATED);
    }

    /**
     * Get all reviews
     */
    public function getAllReview(Request $request)
    {
        $query = Review::with('user', 'product');

        if ($request->filled('status')) {
            $query->where('status', (bool) $request->status);
        }

        $items = $query->latest()->get();

        return response()->json([
            'count' => $items->count(),
            'items' => $items
        ], Response::HTTP_OK);
    }

    /**
     * Get reviews for a product
     */
    public function getReviewByProduct($productId)
    {
        $items = Review::where('product_id', $productId)
            ->where('status', 1)
            ->with('user', 'product')
            ->latest()
            ->get();

        return response()->json([
            'count' => $items->count(),
            'items' => $items
        ], Response::HTTP_OK);
    }

    /**
     * Get reviews by a user
     */
    public function getReviewByUser($userId)
    {
        $items = Review::where('user_id', $userId)
            ->where('status', 1)
            ->with('user', 'product')
            ->latest()
            ->get();

        return response()->json([
            'count' => $items->count(),
            'items' => $items
        ], Response::HTTP_OK);
    }

    /**
     * Update a review by user
     */
    public function updateReviewByUser(Request $request, $id)
    {
        $review = Review::find($id);

        if (! $review) {
            return response()->json(['message' => 'Review not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'comment' => 'nullable|string',
            'star_count' => 'nullable|integer|min:1|max:5',
            'status' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:0',
            'type' => 'nullable|string',
        ]);

        if ((int) $review->user_id !== (int) $data['user_id']) {
            return response()->json(['message' => 'You are not allowed to update this review.'], Response::HTTP_FORBIDDEN);
        }

        if (array_key_exists('comment', $data)) {
            $review->comment = $data['comment'];
        }
        if (array_key_exists('star_count', $data)) {
            $review->star_count = $data['star_count'];
        }
        if (array_key_exists('status', $data)) {
            $review->status = (bool) $data['status'];
        }
        if (array_key_exists('priority', $data)) {
            $review->priority = $data['priority'];
        }
        if (array_key_exists('type', $data)) {
            $review->type = $data['type'];
        }

        $review->save();

        return response()->json([
            'message' => 'Review updated successfully',
            'review' => $review->load('user', 'product')
        ], Response::HTTP_OK);
    }

    /**
     * Remove (soft) a review
     */
    public function removeReview($id)
    {
        $review = Review::find($id);

        if (! $review) {
            return response()->json(['message' => 'Review not found.'], Response::HTTP_NOT_FOUND);
        }

        $review->status = false;
        $review->save();

        return response()->json(['message' => 'Review removed successfully.'], Response::HTTP_OK);
    }


    
}
