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

    private function appendSettlementStatusToRows($rows)
    {
        $orderIds = $rows->pluck('order_id')->filter()->unique()->values();

        if ($orderIds->isEmpty()) {
            return $rows;
        }

        $settlementsByOrder = OrderSettlement::whereIn('order_id', $orderIds)
            ->get()
            ->groupBy('order_id');

        return $rows->transform(function ($row) use ($settlementsByOrder) {
            $orderSettlements = $settlementsByOrder->get($row->order_id, collect());
            $row->setAttribute('settlement_status', $this->buildSettlementStatusObject($orderSettlements));

            return $row;
        });
    }

    public function list(Request $request)
    {
        try {
            $query = OrderSettlement::with(['order', 'payableUser', 'vendor', 'createdBy']);

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

            $query->latest();

            if ($request->filled('all') && (int) $request->get('all') === 1) {
                $settlements = $this->appendSettlementStatusToRows($query->get());

                return $this->success('Order settlements fetched successfully', $settlements);
            }

            $perPage = (int) $request->get('per_page', 20);
            $settlements = $query->paginate($perPage);
            $settlements->setCollection($this->appendSettlementStatusToRows($settlements->getCollection()));

            return $this->success('Order settlements fetched successfully', $settlements);
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
}
