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
            $query = PriceUpdateLog::query()
                ->with([
                    'updatedBy:id,name',
                    'product:id,name,unit_price',
                ]);

            if ($request->filled('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            $logs = $query->latest()->get();

            return $this->success('Price update logs fetched successfully', $logs);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
