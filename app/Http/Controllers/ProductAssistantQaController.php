<?php

namespace App\Http\Controllers;

use App\Models\ProductAssistantQa;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductAssistantQaController extends Controller
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
            'errors' => $errors,
        ], $code);
    }

    /**
     * GET /product-assistant-qas/list
     */
    public function list(Request $request)
    {
        try {
            $query = ProductAssistantQa::with('product:id,name,sku,thumbnail_img');

            if ($request->filled('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            }

            if ($request->filled('all') && (int) $request->get('all') === 1) {
                return $this->success('Product assistant Q&As fetched', $query->latest()->get());
            }

            $perPage = (int) $request->get('per_page', 20);
            $items = $query->latest()->paginate($perPage);

            return $this->success('Product assistant Q&As fetched', $items);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /product-assistant-qas/product/{productId}
     */
    public function getByProduct($productId)
    {
        try {
            $product = Product::find($productId);
            if (!$product) {
                return $this->failed('Product not found', null, 404);
            }

            $qas = ProductAssistantQa::where('product_id', $productId)
                ->where('is_active', true)
                ->latest()
                ->get();

            return $this->success('Product assistant Q&As fetched successfully', [
                'product' => $product->only(['id', 'name', 'sku']),
                'qas' => $qas,
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /product-assistant-qas/add
     */
    public function add(Request $request)
    {
        try {
            $validated = $request->validate([
                'product_id' => ['nullable', 'integer', 'exists:products,id'],
                'question' => ['nullable', 'string'],
                'answer' => ['required', 'string'],
                'css_styles' => ['nullable', 'string'],
                'is_active' => ['sometimes', 'boolean'],
            ]);

            $qa = ProductAssistantQa::create($validated);

            return $this->success('Product assistant Q&A created successfully', $qa, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /product-assistant-qas/details/{id}
     */
    public function details($id)
    {
        try {
            $qa = ProductAssistantQa::with('product:id,name,sku,thumbnail_img')->find($id);

            if (!$qa) {
                return $this->failed('Product assistant Q&A not found', null, 404);
            }

            return $this->success('Product assistant Q&A fetched successfully', $qa);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /product-assistant-qas/update/{id}
     * POST /product-assistant-qas/update/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $qa = ProductAssistantQa::find($id);

            if (!$qa) {
                return $this->failed('Product assistant Q&A not found', null, 404);
            }

            $validated = $request->validate([
                'product_id' => ['nullable', 'integer', 'exists:products,id'],
                'question' => ['nullable', 'string'],
                'answer' => ['sometimes', 'required', 'string'],
                'css_styles' => ['nullable', 'string'],
                'is_active' => ['sometimes', 'boolean'],
            ]);

            $qa->update($validated);

            return $this->success('Product assistant Q&A updated successfully', $qa->fresh('product:id,name,sku,thumbnail_img'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PATCH /product-assistant-qas/toggle-active/{id}
     */
    public function toggleActive($id)
    {
        try {
            $qa = ProductAssistantQa::find($id);

            if (!$qa) {
                return $this->failed('Product assistant Q&A not found', null, 404);
            }

            $qa->update(['is_active' => !$qa->is_active]);

            return $this->success('Product assistant Q&A status updated', $qa);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /product-assistant-qas/delete/{id}
     * DELETE /product-assistant-qas/remove/{id}
     */
    public function delete($id)
    {
        try {
            $qa = ProductAssistantQa::find($id);

            if (!$qa) {
                return $this->failed('Product assistant Q&A not found', null, 404);
            }

            $qa->delete();

            return $this->success('Product assistant Q&A deleted successfully');
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
