<?php

namespace App\Http\Controllers;


use App\Models\ProductDiscount;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductDiscountController extends Controller
{
    private function success($message, $data = null, int $code = 200)
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
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
     * POST /product-discounts/create
     */
    public function create(Request $request)
    {
        try {
            $validated = $request->validate([
                'product_id' => ['required', 'integer', 'exists:products,id'],
                'type' => ['required', 'in:flat,percentage'],
                'value' => ['required', 'numeric', 'min:0'],
                'start_at' => ['nullable', 'date'],
                'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            // enforce uniqueness of product_id (migration also enforces it)
            $exists = ProductDiscount::where('product_id', $validated['product_id'])->first();
            if ($exists) {
                return $this->failed('A discount already exists for this product', null, 409);
            }

            $pd = ProductDiscount::create([
                'product_id' => $validated['product_id'],
                'type' => $validated['type'],
                'value' => $validated['value'],
                'start_at' => $validated['start_at'] ?? null,
                'end_at' => $validated['end_at'] ?? null,
                'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
            ]);

            return $this->success('Product discount created successfully', $pd, 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /product-discounts/list
     */
    public function list(Request $request)
    {
        try {
            $query = ProductDiscount::with('product');

            if ($request->filled('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            $perPage = (int) $request->get('per_page', 20);
            $items = $query->latest()->paginate($perPage);

            return $this->success('Product discounts fetched successfully', $items);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /product-discounts/details/{id}
     */
    public function details($id)
    {
        try {
            $pd = ProductDiscount::with('product')->find($id);
            if (!$pd) {
                return $this->failed('Product discount not found', null, 404);
            }

            return $this->success('Product discount fetched successfully', $pd);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /product-discounts/update/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $pd = ProductDiscount::find($id);
            if (!$pd) {
                return $this->failed('Product discount not found', null, 404);
            }

            $validated = $request->validate([
                'type' => ['nullable', 'in:flat,percentage'],
                'value' => ['nullable', 'numeric', 'min:0'],
                'start_at' => ['nullable', 'date'],
                'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            if (array_key_exists('type', $validated)) {
                $pd->type = $validated['type'];
            }
            if (array_key_exists('value', $validated)) {
                $pd->value = $validated['value'];
            }
            if (array_key_exists('start_at', $validated)) {
                $pd->start_at = $validated['start_at'];
            }
            if (array_key_exists('end_at', $validated)) {
                $pd->end_at = $validated['end_at'];
            }
            if (array_key_exists('is_active', $validated)) {
                $pd->is_active = (bool) $validated['is_active'];
            }

            $pd->save();

            return $this->success('Product discount updated successfully', $pd);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /product-discounts/delete/{id}
     */
    public function delete($id)
    {
        try {
            $pd = ProductDiscount::find($id);
            if (!$pd) {
                return $this->failed('Product discount not found', null, 404);
            }

            $pd->delete();

            return $this->success('Product discount deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
