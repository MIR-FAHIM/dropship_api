<?php

namespace App\Http\Controllers;

use App\Service\OrderHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class OrderHistoryController extends Controller
{
    public function __construct(private OrderHistoryService $history) {}

    public function admin(Request $request): JsonResponse
    {
        return $this->respond($request);
    }

    public function vendor(Request $request): JsonResponse
    {
        return $this->respond($request, (int) $request->attributes->get('order_history_vendor_id'));
    }

    public function adminOrders(Request $request): JsonResponse
    {
        return $this->respond($request, ordersOnly: true);
    }

    public function vendorOrders(Request $request): JsonResponse
    {
        return $this->respond($request, (int) $request->attributes->get('order_history_vendor_id'), true);
    }

    private function respond(Request $request, ?int $vendorId = null, bool $ordersOnly = false): JsonResponse
    {
        $isVendor = $vendorId !== null;
        $groupByVendorProduct = $isVendor || $request->input('group_by') === 'vendor_product';

        // shop_id is the fulfillment vendor ID, as persisted by checkout, not shops.id.
        $shopExists = Rule::exists('vendors', 'id');
        if ($vendorId !== null) {
            $shopExists->where('id', $vendorId);
        }

        $validator = Validator::make($request->query(), [
            'product_id' => [Rule::requiredIf($ordersOnly && ! $request->boolean('unlinked_product')), 'nullable', 'integer', 'min:1', 'exists:products,id', 'prohibits:unlinked_product'],
            'unlinked_product' => ['sometimes', 'accepted', 'prohibits:product_id'],
            'shop_id' => ['nullable', 'integer', 'min:1', $shopExists],
            'vendor_id' => [$isVendor ? 'prohibited' : 'nullable', 'integer', 'min:1', Rule::exists('vendors', 'id')],
            'group_by' => ['sometimes', 'string', Rule::in(['product', 'vendor_product'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('date_from'), ['after_or_equal:date_from'])],
            'status' => ['nullable', 'integer', 'min:1', 'exists:order_statuses,id'],
            'payment_status' => ['nullable', Rule::in(['unpaid', 'paid', 'failed', 'refunded', 'pending', 'partially_paid'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'history_page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'history_per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'failed', 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $filters = $validator->validated();
        if (isset($filters['vendor_id']) && ! isset($filters['shop_id'])) {
            $filters['shop_id'] = $filters['vendor_id'];
        }

        $data = [
            'grouped_by' => $groupByVendorProduct ? ['product_id', 'shop_id'] : ['product_id'],
            'product_sales_basis' => 'stored_line_total_before_order_discount_and_shipping',
            'discount_allocation' => 'not_available',
        ];
        if (! $ordersOnly) {
            $data['groups'] = $this->history->groups($filters, $vendorId, $groupByVendorProduct)->withQueryString();
        }
        if ($ordersOnly || isset($filters['product_id']) || ! empty($filters['unlinked_product'])) {
            $data['orders'] = $this->history->orders($filters, $vendorId, ! $ordersOnly)->withQueryString();
        }

        return response()->json(['status' => 'success', 'message' => 'Order history fetched successfully', 'data' => $data]);
    }
}
