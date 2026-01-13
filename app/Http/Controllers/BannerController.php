<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Banner;
use Illuminate\Http\Response;

class BannerController extends Controller
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
     * POST /banners/add
     */
    public function addBanner(Request $request)
    {
        try {
            $validated = $request->validate([
                'banner_name' => ['required', 'string', 'max:255'],
                'title' => ['nullable', 'string', 'max:255'],
                'related_product_id' => ['nullable', 'integer', 'exists:products,id'],
                'related_category_id' => ['nullable', 'integer', 'exists:categories,id'],
                // Accept either an uploaded file (`image`) or a direct `image_path` string
                'image' => ['nullable', 'image', 'max:2048'], // max 2MB
                'image_path' => ['required_without:image', 'nullable', 'string', 'max:255'],
                'note' => ['nullable', 'string'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            // Handle file upload if provided, otherwise use provided image_path
            $imagePath = null;
            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('banners', 'public');
                $imagePath = $path; // e.g. /storage/banners/...
            } else {
                $imagePath = $validated['image_path'] ?? null;
            }

            $banner = Banner::create([
                'banner_name' => $validated['banner_name'],
                'title' => $validated['title'] ?? null,
                'related_product_id' => $validated['related_product_id'] ?? null,
                'related_category_id' => $validated['related_category_id'] ?? null,
                'image_path' => $imagePath,
                'note' => $validated['note'] ?? null,
                'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
            ]);

            return $this->success('Banner created successfully', $banner, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /banners/active
     */
    public function getActiveBanner()
    {
        try {
            $banners = Banner::where('is_active', 1)
                ->with(['product', 'category'])
                ->latest()
                ->get();

            return $this->success('Active banners fetched', $banners);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /banners/remove/{id}
     * Soft-remove by setting is_active = false
     */
    public function removeBanner($id)
    {
        try {
            $banner = Banner::find($id);
            if (! $banner) {
                return $this->failed('Banner not found', null, 404);
            }

            $banner->is_active = false;
            $banner->save();

            return $this->success('Banner removed successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
