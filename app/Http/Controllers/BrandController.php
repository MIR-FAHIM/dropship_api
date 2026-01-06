<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrandController extends Controller
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
     * POST /brands/create
     */
    public function createBrand(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => ['nullable', 'string', 'max:255'],
                'slug' => ['nullable', 'string', 'max:255', 'unique:brands,slug'],
                'logo' => ['nullable', 'string', 'max:255'],
                'status' => ['nullable', 'string', 'max:50'],
            ]);

            $brand = Brand::create($validated);

            return $this->success('Brand created successfully', $brand, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /brands/list?status=&per_page=&all=1
     */
    public function listBrands(Request $request)
    {
        try {
            $query = Brand::query();

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $query->latest();

            // /brands/list?all=1 (no pagination)
            if ($request->filled('all') && (int) $request->get('all') === 1) {
                $brands = $query->get();
                return $this->success('Brands fetched successfully', $brands);
            }

            $perPage = (int) $request->get('per_page', 20);
            $brands = $query->paginate($perPage);

            return $this->success('Brands fetched successfully', $brands);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /brands/details/{id}
     */
    public function getBrandDetails($id)
    {
        try {
            $brand = Brand::find($id);

            if (!$brand) {
                return $this->failed('Brand not found', null, 404);
            }

            return $this->success('Brand fetched successfully', $brand);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /brands/update/{id}
     */
    public function updateBrand(Request $request, $id)
    {
        try {
            $brand = Brand::find($id);

            if (!$brand) {
                return $this->failed('Brand not found', null, 404);
            }

            $validated = $request->validate([
                'name' => ['nullable', 'string', 'max:255'],
                'slug' => ['nullable', 'string', 'max:255', Rule::unique('brands', 'slug')->ignore($brand->id)],
                'logo' => ['nullable', 'string', 'max:255'],
                'status' => ['nullable', 'string', 'max:50'],
            ]);

            $brand->fill($validated);
            $brand->save();

            return $this->success('Brand updated successfully', $brand);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /brands/delete/{id}
     */
    public function deleteBrand($id)
    {
        try {
            $brand = Brand::find($id);

            if (!$brand) {
                return $this->failed('Brand not found', null, 404);
            }

            // If you want to prevent deleting brands that still have products:
            // if ($brand->products()->exists()) {
            //     return $this->failed('Cannot delete: brand has products', null, 409);
            // }

            $brand->delete();

            return $this->success('Brand deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
