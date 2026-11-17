<?php

namespace App\Http\Controllers;

use App\Models\PriceUpdateLog;
use Illuminate\Http\Request;

class PriceUpdateLogController extends Controller
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

    public function list(Request $request)
    {
        try {
            // Filters: product_id, search (product name / SKU / id), vendor_id (supplier),
            // date_from / date_to (YYYY-MM-DD, Bangladesh time), page, per_page (max 100).
            $request->validate([
                'product_id' => ['nullable', 'integer'],
                'vendor_id' => ['nullable', 'integer'],
                'search' => ['nullable', 'string', 'max:100'],
                'date_from' => ['nullable', 'date_format:Y-m-d'],
                'date_to' => ['nullable', 'date_format:Y-m-d'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $query = PriceUpdateLog::query()
                ->with([
                    'updatedBy:id,name',
                    'product:id,name,sku,unit_price,admin_price,vendor_id',
                    'product.vendor:id,shop_name',
                ]);

            if ($request->filled('product_id')) {
                $query->where('product_id', (int) $request->product_id);
            }

            if ($request->filled('vendor_id')) {
                $vendorId = (int) $request->vendor_id;
                $query->whereHas('product', fn ($p) => $p->where('vendor_id', $vendorId));
            }

            $search = trim((string) $request->get('search', ''));
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    if (ctype_digit($search)) {
                        $q->where('product_id', (int) $search)->orWhereHas('product', fn ($p) => $p
                            ->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"));
                    } else {
                        $q->whereHas('product', fn ($p) => $p
                            ->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"));
                    }
                });
            }

            foreach (['date_from' => 'startOfDay', 'date_to' => 'endOfDay'] as $param => $edge) {
                if ($request->filled($param)) {
                    $boundary = \Carbon\Carbon::createFromFormat('Y-m-d', $request->get($param), 'Asia/Dhaka')->{$edge}()->utc();
                    $query->where('created_at', $param === 'date_from' ? '>=' : '<=', $boundary);
                }
            }

            // Newest first; id breaks ties when several prices changed in the same second.
            $logs = $query->orderByDesc('created_at')->orderByDesc('id')
                ->paginate((int) $request->get('per_page', 20));

            return $this->success('Price update logs fetched successfully', $logs);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
