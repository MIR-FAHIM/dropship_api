<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\SettlementInvoice;
use App\Models\ResellerTransaction;
use App\Models\Vendor;
use App\Models\User;
use App\Service\CompanyTransactionService;
use App\Service\MuthobartaSmsService;
use App\Service\NotificationService;
use App\Service\OrderAccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderSettlementController extends Controller
{
    protected $smsService;
    protected $companyTransactions;
    protected $notificationService;
    protected $accountingService;

    public function __construct(
        MuthobartaSmsService $smsService,
        CompanyTransactionService $companyTransactions,
        NotificationService $notificationService,
        OrderAccountingService $accountingService
    )
    {
        $this->smsService = $smsService;
        $this->companyTransactions = $companyTransactions;
        $this->notificationService = $notificationService;
        $this->accountingService = $accountingService;
    }

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
        $vendorSettlements = $settlements->where('settlement_type', OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE);
        $statusObject = [];

        foreach ($this->settlementTypes() as $type) {
            if ($type === OrderSettlement::TYPE_SUPPLIER_PRODUCT_PRICE && $vendorSettlements->count() > 1) {
                $allSettled = $vendorSettlements->every(fn($s) => $s->status === OrderSettlement::STATUS_SETTLED);
                $anySettled = $vendorSettlements->contains(fn($s) => $s->status === OrderSettlement::STATUS_SETTLED);

                $statusObject[$type] = [
                    'exists' => true,
                    'is_multivendor' => true,
                    'count' => $vendorSettlements->count(),
                    'id' => $vendorSettlements->first()?->id,
                    'status' => $allSettled ? OrderSettlement::STATUS_SETTLED : ($anySettled ? 'partially_settled' : OrderSettlement::STATUS_PENDING),
                    'is_settled' => $allSettled,
                    'settleable_amount' => (float) $vendorSettlements->sum('settleable_amount'),
                    'settled_trx_id' => $allSettled ? $vendorSettlements->pluck('settled_trx_id')->filter()->join(', ') : null,
                    'settled_at' => $allSettled ? $vendorSettlements->max('settled_at') : null,
                ];
                continue;
            }

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

    private function shouldCreateResellerCredit(OrderSettlement $settlement): bool
    {
        return $settlement->status === OrderSettlement::STATUS_SETTLED
            && $settlement->settlement_type === OrderSettlement::TYPE_RESELLER_PROFIT
            && $settlement->user_type === 'dropshipper'
            && (float) $settlement->settleable_amount > 0;
    }

    private function createResellerCreditForSettlement(OrderSettlement $settlement): void
    {
        if (!$this->shouldCreateResellerCredit($settlement)) {
            return;
        }

        $exists = ResellerTransaction::where('ref_id', $settlement->id)
            ->where('type', 'order_settlement')
            ->where('trx_type', 'credit')
            ->exists();

        if ($exists) {
            return;
        }

        $resellerId = $settlement->payable_user_id ?: Order::where('id', $settlement->order_id)->value('user_id');

        ResellerTransaction::create([
            'amount' => $settlement->settleable_amount,
            'reseller_id' => $resellerId,
            'ref_id' => $settlement->id,
            'trx_id' => $settlement->settled_trx_id ?: $settlement->trx_id,
            'trx_type' => 'credit',
            'note' => 'Credit transaction from reseller profit settlement #' . $settlement->id,
            'status' => 'completed',
            'source' => 'wallet',
            'order_id' => $settlement->order_id,
            'type' => 'order_settlement',
        ]);
    }

    private function shouldSendSettlementSms(OrderSettlement $settlement): bool
    {
        return $settlement->status === OrderSettlement::STATUS_SETTLED
            && (
                $settlement->user_type === 'vendor'
                || (
                    $settlement->user_type === 'dropshipper'
                    && $settlement->settlement_type === OrderSettlement::TYPE_RESELLER_PROFIT
                )
            );
    }

    private function settlementSmsReceiver(OrderSettlement $settlement): ?string
    {
        if ($settlement->payableUser?->phone) {
            return $settlement->payableUser->phone;
        }

        if ($settlement->user_type === 'vendor' && $settlement->vendor?->user?->phone) {
            return $settlement->vendor->user->phone;
        }

        if ($settlement->user_type === 'dropshipper' && $settlement->order?->user?->phone) {
            return $settlement->order->user->phone;
        }

        return null;
    }

    private function sendSettlementSms(OrderSettlement $settlement): void
    {
        $settlement->loadMissing(['order.user', 'payableUser', 'vendor.user']);

        if (!$this->shouldSendSettlementSms($settlement)) {
            return;
        }

        $receiver = $this->settlementSmsReceiver($settlement);
        if (!$receiver) {
            return;
        }

        $orderNumber = $settlement->order?->order_number ?: $settlement->order_id;
        $amount = number_format((float) $settlement->settleable_amount, 2, '.', '');
        $currency = $settlement->currency ?: 'BDT';
        $trxText = $settlement->settled_trx_id ? " Trx ID: {$settlement->settled_trx_id}." : '';

        $message = $settlement->user_type === 'vendor'
            ? "Your vendor settlement for order {$orderNumber} is settled. Amount {$amount} {$currency}.{$trxText}"
            : "Your profit added to Balance for order {$orderNumber}. Amount {$amount} {$currency}.{$trxText}";

        $this->smsService->send($receiver, $message, 'order_settlement');
    }

    private function settlementNotificationReceiver(OrderSettlement $settlement): ?int
    {
        if ($settlement->payable_user_id) {
            return (int) $settlement->payable_user_id;
        }

        if ($settlement->user_type === 'vendor' && $settlement->vendor?->user_id) {
            return (int) $settlement->vendor->user_id;
        }

        if ($settlement->user_type === 'dropshipper' && $settlement->order?->user_id) {
            return (int) $settlement->order->user_id;
        }

        return null;
    }

    private function sendSettlementNotification(OrderSettlement $settlement): void
    {
        $settlement->loadMissing(['order.user', 'payableUser', 'vendor.user']);

        $orderNumber = $settlement->order?->order_number ?: $settlement->order_id;
        $amount = number_format((float) $settlement->settleable_amount, 2, '.', '');
        $currency = $settlement->currency ?: 'BDT';
        $receiverId = $this->settlementNotificationReceiver($settlement);

        if ($this->shouldSendSettlementSms($settlement) && $receiverId) {
            $title = $settlement->user_type === 'vendor'
                ? 'Vendor Settlement Paid'
                : 'Reseller Profit Added';

            $subtitle = $settlement->user_type === 'vendor'
                ? "Settlement for order {$orderNumber} paid. Amount {$amount} {$currency}."
                : "Profit for order {$orderNumber} added to your balance. Amount {$amount} {$currency}.";

            $this->notificationService->createNotificationSafely([
                'title'      => $title,
                'subtitle'   => $subtitle,
                'created_by' => $settlement->created_by,
                'send_to'    => $receiverId,
                'is_seen'    => false,
                'type'       => 'payment',
                'is_active'  => true,
                'image'      => null,
                'module'     => 'settlement',
            ]);
        }

        $this->notificationService->createAdminNotifications([
            'title'      => 'Order Settlement Updated',
            'subtitle'   => "{$settlement->settlement_type} for order {$orderNumber} is {$settlement->status}. Amount {$amount} {$currency}.",
            'created_by' => $settlement->created_by,
            'is_seen'    => false,
            'type'       => 'payment',
            'is_active'  => true,
            'image'      => null,
            'module'     => 'settlement',
        ]);
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
            $wasSettled = $settlement->status === OrderSettlement::STATUS_SETTLED;
            $settledTrxId = array_key_exists('settled_trx_id', $validated)
                ? $validated['settled_trx_id']
                : $settlement->settled_trx_id;
            $requiresSettledTrxId = $settlement->user_type === 'vendor'
                || (
                    $settlement->user_type === 'dropshipper'
                    && $settlement->settlement_type !== OrderSettlement::TYPE_RESELLER_PROFIT
                );

            if ($status === OrderSettlement::STATUS_SETTLED && $requiresSettledTrxId && empty($settledTrxId)) {
                return $this->failed('settled_trx_id is required to settle vendor or dropshipper settlement', null, 422);
            }

            DB::transaction(function () use ($settlement, $validated, $status) {
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

                $this->createResellerCreditForSettlement($settlement);
                $this->companyTransactions->recordSettlement($settlement);
            });

            $settlement->refresh();
            if (!$wasSettled && $settlement->status === OrderSettlement::STATUS_SETTLED) {
                $this->sendSettlementSms($settlement);
                $this->sendSettlementNotification($settlement);
            }

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

            $this->notificationService->createAdminNotifications([
                'title'      => 'Settlement Transaction ID Added',
                'subtitle'   => "Settlement #{$settlement->id} transaction ID added.",
                'created_by' => $settlement->created_by,
                'is_seen'    => false,
                'type'       => 'payment',
                'is_active'  => true,
                'image'      => null,
                'module'     => 'settlement',
            ]);

            return $this->success('Settled transaction id added successfully', $settlement->load(['order', 'payableUser', 'vendor', 'createdBy']));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /order-settlements/orders/{orderId}/breakdown
     */
    public function getOrderBreakdown($orderId)
    {
        try {
            $order = Order::find($orderId);
            if (!$order) {
                return $this->failed('Order not found', null, 404);
            }

            $breakdown = $this->accountingService->calculateOrderStakeBreakdown($order);
            return $this->success('Order financial breakdown fetched successfully', $breakdown);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /order-settlements/orders/{orderId}/update-breakdown
     */
    public function updateOrderBreakdown(Request $request, $orderId)
    {
        try {
            $order = Order::find($orderId);
            if (!$order) {
                return $this->failed('Order not found', null, 404);
            }

            $validated = $request->validate([
                'collectable_amount' => 'nullable|numeric|min:0',
                'courier_delivery_fee' => 'nullable|numeric|min:0',
                'courier_cod_fee' => 'nullable|numeric|min:0',
                'remaining_cod' => 'nullable|numeric|min:0',
                'supplier_vendor_price' => 'nullable|numeric|min:0',
                'admin_base_price' => 'nullable|numeric|min:0',
                'reseller_price' => 'nullable|numeric|min:0',
                'dropshipper_profit' => 'nullable|numeric|min:0',
                'admin_note' => 'nullable|string',
                'items' => 'nullable|array',
                'items.*.id' => 'required|integer',
                'items.*.unit_price' => 'nullable|numeric|min:0',
                'items.*.admin_price' => 'nullable|numeric|min:0',
                'items.*.reseller_price' => 'nullable|numeric|min:0',
            ]);

            $adminUserId = $request->user()?->id ?: (int) $request->input('created_by');
            $updatedBreakdown = $this->accountingService->saveBreakdownAdjustments($order, $validated, $adminUserId);

            return $this->success('Order breakdown adjusted successfully', $updatedBreakdown);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /order-settlements/vendor-invoice
     */
    public function createVendorInvoice(Request $request)
    {
        try {
            $validated = $request->validate([
                'vendor_id' => 'required|integer|exists:vendors,id',
                'order_ids' => 'required|array|min:1',
                'order_ids.*' => 'integer|exists:orders,id',
                'invoice_number' => 'nullable|string|max:50',
                'payment_method' => 'nullable|string|max:50',
                'trx_id' => 'nullable|string|max:100',
                'status' => 'nullable|string|in:pending,paid,approved,rejected',
                'admin_payment_details' => 'nullable|array',
                'note' => 'nullable|string',
            ]);

            $adminUserId = $request->user()?->id ?: (int) $request->input('created_by');
            $invoice = $this->accountingService->createVendorSettlementInvoice($validated, $adminUserId);

            return $this->success('Vendor settlement invoice created successfully', $invoice, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /order-settlements/vendor-invoices
     */
    public function listVendorInvoices(Request $request)
    {
        try {
            $query = SettlementInvoice::with(['vendor', 'creator', 'payer', 'approver', 'rejecter'])->orderByDesc('id');

            // If logged in user is a vendor, restrict to their vendor_id
            $user = $request->user();
            if ($user && ($user->user_type === 'vendor' || $user->role === 'vendor')) {
                $vendor = Vendor::where('user_id', $user->id)->first();
                if ($vendor) {
                    $query->where('vendor_id', $vendor->id);
                } else {
                    return $this->success('Vendor settlement invoices fetched successfully', collect());
                }
            } elseif ($request->filled('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('invoice_number', 'like', "%{$search}%")
                      ->orWhere('trx_id', 'like', "%{$search}%");
                });
            }

            $perPage = (int) $request->get('per_page', 20);
            $invoices = $query->paginate($perPage);

            return $this->success('Vendor settlement invoices fetched successfully', $invoices);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /order-settlements/vendor-invoices/{id}
     */
    public function vendorInvoiceDetails(Request $request, $id)
    {
        try {
            $invoice = SettlementInvoice::with(['vendor', 'creator', 'payer', 'approver', 'rejecter'])->find($id);
            if (!$invoice) {
                return $this->failed('Settlement invoice not found', null, 404);
            }

            $user = $request->user();
            if ($user && ($user->user_type === 'vendor' || $user->role === 'vendor')) {
                $vendor = Vendor::where('user_id', $user->id)->first();
                if ($vendor && (int) $invoice->vendor_id !== (int) $vendor->id) {
                    return $this->failed('Unauthorized access to invoice', null, 403);
                }
            }

            return $this->success('Settlement invoice details fetched successfully', $invoice);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /order-settlements/vendor-invoices/{id}/pay
     */
    public function payVendorInvoice(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'payment_method' => 'required|string|max:50',
                'trx_id' => 'required|string|max:100',
                'sender_account' => 'nullable|string|max:100',
                'paid_amount' => 'nullable|numeric|min:0',
                'note' => 'nullable|string',
            ]);

            $userId = $request->user()?->id ?: (int) $request->input('user_id');
            $invoice = $this->accountingService->submitVendorInvoicePayment((int) $id, $validated, $userId);

            return $this->success('Invoice payment submitted successfully', $invoice);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\InvalidArgumentException $e) {
            return $this->failed($e->getMessage(), null, 400);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /order-settlements/vendor-invoices/{id}/approve
     */
    public function approveVendorInvoice(Request $request, $id)
    {
        try {
            $adminUserId = $request->user()?->id ?: (int) $request->input('created_by');
            $adminTrxId = $request->input('trx_id');
            $invoice = $this->accountingService->approveVendorInvoice((int) $id, $adminTrxId, $adminUserId);

            return $this->success('Vendor invoice approved and settled successfully', $invoice);
        } catch (\InvalidArgumentException $e) {
            return $this->failed($e->getMessage(), null, 400);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /order-settlements/vendor-invoices/{id}/reject
     */
    public function rejectVendorInvoice(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'rejection_reason' => 'required|string|max:500',
            ]);

            $adminUserId = $request->user()?->id ?: (int) $request->input('created_by');
            $invoice = $this->accountingService->rejectVendorInvoice((int) $id, $validated['rejection_reason'], $adminUserId);

            return $this->success('Vendor invoice rejected successfully', $invoice);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\InvalidArgumentException $e) {
            return $this->failed($e->getMessage(), null, 400);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /order-settlements/admin-payment-accounts
     */
    public function getAdminPaymentAccounts()
    {
        try {
            $accounts = $this->accountingService->getAdminPaymentAccounts();
            return $this->success('Admin payment accounts retrieved successfully', $accounts);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /order-settlements/orders/{orderId}/disburse-reseller-profit
     */
    public function disburseDropshipperProfit(Request $request, $orderId)
    {
        try {
            $order = Order::find($orderId);
            if (!$order) {
                return $this->failed('Order not found', null, 404);
            }

            $validated = $request->validate([
                'amount' => 'nullable|numeric|min:0.01',
                'payment_method' => 'nullable|string|in:wallet,bank,bkash,nagad,cash',
                'trx_id' => 'nullable|string|max:100',
                'note' => 'nullable|string',
            ]);

            $adminUserId = $request->user()?->id ?: (int) $request->input('created_by');
            $result = $this->accountingService->disburseDropshipperProfitDirectly($order, $validated, $adminUserId);

            return $this->success($result['message'], $result);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }
}
