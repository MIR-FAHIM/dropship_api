<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderSettlement;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderSettlementController extends Controller
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

    private function settlementTypes(): array
    {
        return [
            OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE,
            OrderSettlement::TYPE_RESELLER_PROFIT,
            OrderSettlement::TYPE_SHIPPING_CHARGE,
            OrderSettlement::TYPE_COMPANY_LOGISTIC_EARNING,
            OrderSettlement::TYPE_COMPANY_PRODUCT_COMMISSION,
        ];
    }

    private function buildSettlementStatusObject($settlements): array
    {
        $settlementsByType = $settlements->keyBy('settlement_type');
        $statusObject = [];

        foreach ($this->settlementTypes() as $type) {
            $settlement = $settlementsByType->get($type);

            $statusObject[$type] = [
                'exists' => (bool) $settlement,
                'id' => $settlement?->id,
                'status' => $settlement?->status ?? 'missing',
                'is_settled' => $settlement?->status === OrderSettlement::STATUS_SETTLED,
                'settleable_amount' => (float) ($settlement?->settleable_amount ?? 0),
                'settled_trx_id' => $settlement?->settled_trx_id,
                'settled_at' => $settlement?->settled_at,
            ];
        }

        return $statusObject;
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('user_type')) {
            $query->where('user_type', $request->user_type);
        }

        if ($request->filled('settlement_type')) {
            $query->where('settlement_type', $request->settlement_type);
        }

        if ($request->filled('payable_user_id')) {
            $query->where('payable_user_id', $request->payable_user_id);
        }

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        return $query;
    }

    private function buildOrderSettlementListItems($orderRows)
    {
        $orderIds = $orderRows->pluck('order_id')->filter()->unique()->values();

        if ($orderIds->isEmpty()) {
            return collect();
        }

        $ordersById = Order::whereIn('id', $orderIds)
            ->get(['id', 'order_number', 'status', 'payment_status', 'total'])
            ->keyBy('id');

        $settlementsByOrder = OrderSettlement::whereIn('order_id', $orderIds)
            ->get()
            ->groupBy('order_id');

        return $orderRows->map(function ($row) use ($ordersById, $settlementsByOrder) {
            $orderSettlements = $settlementsByOrder->get($row->order_id, collect());
            $order = $ordersById->get($row->order_id);

            return [
                'order_id' => (int) $row->order_id,
                'order_number' => $order?->order_number,
                'order_status' => $order?->status,
                'payment_status' => $order?->payment_status,
                'order_total' => (float) ($order?->total ?? 0),
                'latest_settlement_id' => (int) $row->latest_settlement_id,
                'total_settleable_amount' => (float) $orderSettlements->sum('settleable_amount'),
                'total_pending_amount' => (float) $orderSettlements
                    ->where('status', OrderSettlement::STATUS_PENDING)
                    ->sum('settleable_amount'),
                'total_settled_amount' => (float) $orderSettlements
                    ->where('status', OrderSettlement::STATUS_SETTLED)
                    ->sum('settleable_amount'),
                'settlement_status' => $this->buildSettlementStatusObject($orderSettlements),
            ];
        })->values();
    }

    public function list(Request $request)
    {
        try {
            $query = $this->applyFilters(OrderSettlement::query(), $request)
                ->select('order_id')
                ->selectRaw('MAX(id) as latest_settlement_id')
                ->groupBy('order_id')
                ->orderByDesc('latest_settlement_id');

            if ($request->filled('all') && (int) $request->get('all') === 1) {
                $items = $this->buildOrderSettlementListItems($query->get());

                return $this->success('Order settlements fetched successfully', $items);
            }

            $perPage = (int) $request->get('per_page', 20);
            $paginatedOrders = $query->paginate($perPage);
            $items = $this->buildOrderSettlementListItems(collect($paginatedOrders->items()));

            $data = [
                'current_page' => $paginatedOrders->currentPage(),
                'data' => $items,
                'first_page_url' => $paginatedOrders->url(1),
                'from' => $paginatedOrders->firstItem(),
                'last_page' => $paginatedOrders->lastPage(),
                'last_page_url' => $paginatedOrders->url($paginatedOrders->lastPage()),
                'next_page_url' => $paginatedOrders->nextPageUrl(),
                'path' => $paginatedOrders->path(),
                'per_page' => $paginatedOrders->perPage(),
                'prev_page_url' => $paginatedOrders->previousPageUrl(),
                'to' => $paginatedOrders->lastItem(),
                'total' => $paginatedOrders->total(),
            ];

            return $this->success('Order settlements fetched successfully', $data);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function getSettlementRequestByOrder($orderId)
    {
        try {
            $order = Order::find($orderId);
            if (!$order) {
                return $this->failed('Order not found', null, 404);
            }

            $settlements = OrderSettlement::with(['payableUser', 'vendor', 'createdBy'])
                ->where('order_id', $orderId)
                ->orderBy('id')
                ->get();

            return $this->success('Order settlement list fetched successfully', [
                'order' => $order,
                'settlements' => $settlements,
                'settlement_status' => $this->buildSettlementStatusObject($settlements),
                'summary' => [
                    'total_settleable_amount' => (float) $settlements->sum('settleable_amount'),
                    'total_pending_amount' => (float) $settlements
                        ->where('status', OrderSettlement::STATUS_PENDING)
                        ->sum('settleable_amount'),
                    'total_settled_amount' => (float) $settlements
                        ->where('status', OrderSettlement::STATUS_SETTLED)
                        ->sum('settleable_amount'),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function settleNowe(Request $request, $id)
    {
        try {
            $settlement = OrderSettlement::find($id);
            if (!$settlement) {
                return $this->failed('Order settlement not found', null, 404);
            }

            $validated = $request->validate([
                'status' => [
                    'nullable',
                    Rule::in([
                        OrderSettlement::STATUS_PENDING,
                        OrderSettlement::STATUS_SETTLED,
                        OrderSettlement::STATUS_CANCELLED,
                    ]),
                ],
                'settled_trx_id' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('order_settlements', 'settled_trx_id')->ignore($settlement->id),
                ],
                'admin_note' => ['nullable', 'string'],
                'created_by' => ['nullable', 'integer', 'exists:users,id'],
            ]);

            $status = $validated['status'] ?? OrderSettlement::STATUS_SETTLED;
            $settledTrxId = array_key_exists('settled_trx_id', $validated)
                ? $validated['settled_trx_id']
                : $settlement->settled_trx_id;
            $requiresSettledTrxId = in_array($settlement->user_type, ['vendor', 'dropshipper'], true);

            if ($status === OrderSettlement::STATUS_SETTLED && $requiresSettledTrxId && empty($settledTrxId)) {
                return $this->failed('settled_trx_id is required to settle vendor or dropshipper settlement', null, 422);
            }

            $settlement->status = $status;

            if (array_key_exists('settled_trx_id', $validated)) {
                $settlement->settled_trx_id = $validated['settled_trx_id'];
            }

            if (array_key_exists('admin_note', $validated)) {
                $settlement->admin_note = $validated['admin_note'];
            }

            if (array_key_exists('created_by', $validated)) {
                $settlement->created_by = $validated['created_by'];
            }

            $settlement->settled_at = $status === OrderSettlement::STATUS_SETTLED ? now() : null;
            $settlement->save();

            return $this->success('Order settlement status updated successfully', $settlement->load(['order', 'payableUser', 'vendor', 'createdBy']));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    public function addSettledTrxId(Request $request, $id)
    {
        try {
            $settlement = OrderSettlement::find($id);
            if (!$settlement) {
                return $this->failed('Order settlement not found', null, 404);
            }

            $validated = $request->validate([
                'settled_trx_id' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('order_settlements', 'settled_trx_id')->ignore($settlement->id),
                ],
                'admin_note' => ['nullable', 'string'],
                'created_by' => ['nullable', 'integer', 'exists:users,id'],
            ]);

            $settlement->settled_trx_id = $validated['settled_trx_id'];

            if (array_key_exists('admin_note', $validated)) {
                $settlement->admin_note = $validated['admin_note'];
            }

            if (array_key_exists('created_by', $validated)) {
                $settlement->created_by = $validated['created_by'];
            }

            $settlement->save();

            return $this->success('Settled transaction id added successfully', $settlement->load(['order', 'payableUser', 'vendor', 'createdBy']));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
