<?php

namespace App\Http\Controllers;

use App\Models\ProductAttribute;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductAttributeController extends Controller
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
     * POST /product-attributes/create
     */
    public function create(Request $request)
    {
        try {
            $validated = $request->validate([
                'product_id' => ['required', 'integer', 'exists:products,id'],
                'attribute_id' => ['required', 'integer', 'exists:attributes,id'],
                'attribute_value_id' => ['required', 'integer', 'exists:attribute_values,id'],
                'stock' => ['nullable', 'integer', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            // Prevent duplicate combination (DB has unique constraint too)
            $exists = ProductAttribute::where('product_id', $validated['product_id'])
                ->where('attribute_id', $validated['attribute_id'])
                ->where('attribute_value_id', $validated['attribute_value_id'])
                ->first();

            if ($exists) {
                return $this->failed('Product attribute combination already exists', null, 409);
            }

            $pa = ProductAttribute::create([
                'product_id' => $validated['product_id'],
                'attribute_id' => $validated['attribute_id'],
                'attribute_value_id' => $validated['attribute_value_id'],
                'stock' => $validated['stock'] ?? 0,
                'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
            ]);

            return $this->success('Product attribute created successfully', $pa, 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /product-attributes/list
     * Optional query: product_id
     */
    public function list(Request $request)
    {
        try {
            $query = ProductAttribute::with(['product', 'attribute', 'value']);

            if ($request->filled('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            $perPage = (int) $request->get('per_page', 20);
            $items = $query->latest()->paginate($perPage);

            return $this->success('Product attributes fetched successfully', $items);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /product-attributes/details/{id}
     */
    public function details($id)
    {
        try {
            $pa = ProductAttribute::with(['product', 'attribute', 'value'])->find($id);
            if (!$pa) {
                return $this->failed('Product attribute not found', null, 404);
            }

            return $this->success('Product attribute fetched successfully', $pa);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /product-attributes/update/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $pa = ProductAttribute::find($id);
            if (!$pa) {
                return $this->failed('Product attribute not found', null, 404);
            }

            $validated = $request->validate([
                'stock' => ['nullable', 'integer', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            if (array_key_exists('stock', $validated)) {
                $pa->stock = $validated['stock'];
            }

            if (array_key_exists('is_active', $validated)) {
                $pa->is_active = (bool) $validated['is_active'];
            }

            $pa->save();

            return $this->success('Product attribute updated successfully', $pa);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /product-attributes/delete/{id}
     */
    public function delete($id)
    {
        try {
            $pa = ProductAttribute::find($id);
            if (!$pa) {
                return $this->failed('Product attribute not found', null, 404);
            }

            $pa->delete();

            return $this->success('Product attribute deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
