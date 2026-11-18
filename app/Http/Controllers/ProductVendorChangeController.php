<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVendorChange;
use App\Models\Vendor;
use App\Service\ProductVendorChangeService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Admin product page → "Change vendor". Separate from the normal product update
 * because it also changes the SKU and open carts (see ProductVendorChangeService).
 */
class ProductVendorChangeController extends Controller
{
    public function __construct(private readonly ProductVendorChangeService $service)
    {
    }

    /** GET /products/{id}/vendor-change/preview?vendor_id= */
    public function preview(Request $request, $id)
    {
        try {
            $validated = $request->validate(['vendor_id' => ['required', 'integer', 'exists:vendors,id']]);
            $product = Product::find($id);
            if (!$product) {
                return $this->failed('Product not found', null, 404);
            }

            return $this->success('Vendor change preview', $this->service->preview($product, Vendor::findOrFail($validated['vendor_id'])));
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /** POST /products/{id}/vendor-change  {vendor_id, reason, update_sku} */
    public function change(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
                'reason' => ['required', 'string', 'min:3', 'max:500'],
                'update_sku' => ['sometimes', 'boolean'],
            ]);
            $product = Product::find($id);
            if (!$product) {
                return $this->failed('Product not found', null, 404);
            }
            if ((int) $product->vendor_id === (int) $validated['vendor_id']) {
                return $this->failed('The product already belongs to this vendor.', null, 422);
            }

            $admin = $request->attributes->get('api_user') ?? $request->user();
            $result = $this->service->change(
                $product,
                Vendor::findOrFail($validated['vendor_id']),
                trim($validated['reason']),
                $admin?->id,
                (bool) ($validated['update_sku'] ?? true),
            );

            return $this->success('Vendor changed', $result);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /** GET /products/{id}/vendor-changes — history for the product page. */
    public function history($id)
    {
        $rows = ProductVendorChange::with(['fromVendor:id,shop_name', 'toVendor:id,shop_name', 'changer:id,name'])
            ->where('product_id', $id)
            ->latest('id')
            ->limit(50)
            ->get();

        return $this->success('Vendor change history', $rows);
    }

    private function success($message, $data = null, int $code = 200)
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json(['status' => 'failed', 'message' => $message, 'errors' => $errors], $code);
    }
}
