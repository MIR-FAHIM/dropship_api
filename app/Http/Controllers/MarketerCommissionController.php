<?php

namespace App\Http\Controllers;

use App\Models\MarketerClient;
use App\Models\MarketerCommission;
use App\Models\Order;
use App\Models\SettlementInvoice;
use App\Models\User;
use App\Service\MarketerCommissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Partner Portal phase 1: commission overview for the marketer, and commission
 * lines / payouts / rates for the admin. Rules live in MarketerCommissionService.
 */
class MarketerCommissionController extends Controller
{
    // Delivered, Partially Delivered, Completed, Carrybee Paid
    private const DELIVERED_STATUSES = [6, 9, 10, 13];

    public function __construct(private readonly MarketerCommissionService $commissions)
    {
    }

    /* ======================= Marketer panel ======================= */

    /** GET /marketer/overview — cards, connected clients table and the commission formula. */
    public function overview(Request $request): JsonResponse
    {
        $marketer = $request->user();
        $rate = $this->commissions->rateFor($marketer);

        $clients = MarketerClient::where('marketer_user_id', $marketer->id)
            ->with('dropshipper:id,name,business_name')
            ->orderByDesc('accepted_at')
            ->get();
        $clientIds = $clients->pluck('id')->all();

        $orderStats = $clientIds === [] ? collect() : Order::query()
            ->whereIn('marketer_client_id', $clientIds)
            ->selectRaw('marketer_client_id')
            ->selectRaw('COUNT(*) AS placed')
            ->selectRaw('COUNT(CASE WHEN status IN (' . implode(',', self::DELIVERED_STATUSES) . ') THEN 1 END) AS delivered')
            ->selectRaw('COALESCE(SUM(CASE WHEN status IN (' . implode(',', self::DELIVERED_STATUSES) . ') THEN total END), 0) AS sales')
            ->groupBy('marketer_client_id')->toBase()->get()->keyBy('marketer_client_id');

        $commissionByClient = MarketerCommission::where('marketer_user_id', $marketer->id)
            ->whereIn('status', [MarketerCommission::STATUS_EARNED, MarketerCommission::STATUS_PAID])
            ->selectRaw('marketer_client_id, ROUND(SUM(amount), 2) AS commission, ROUND(SUM(base_amount), 2) AS platform_profit')
            ->groupBy('marketer_client_id')->toBase()->get()->keyBy('marketer_client_id');

        // Only what the client consented to share: name, join date, order counts — no phone or shopper data.
        $rows = $clients->map(function (MarketerClient $c) use ($orderStats, $commissionByClient) {
            $s = $orderStats->get($c->id);
            $m = $commissionByClient->get($c->id);

            return [
                'client_id' => $c->id,
                'name' => $c->dropshipper?->name,
                'store' => $c->dropshipper?->business_name,
                'joined_at' => $c->accepted_at,
                'active' => $c->ended_at === null,
                'orders_placed' => (int) ($s->placed ?? 0),
                'orders_delivered' => (int) ($s->delivered ?? 0),
                'sales_volume' => round((float) ($s->sales ?? 0), 2),
                'platform_profit' => round((float) ($m->platform_profit ?? 0), 2),
                'commission' => round((float) ($m->commission ?? 0), 2),
            ];
        })->values();

        return $this->ok('Marketer overview', [
            'marketer' => ['id' => $marketer->id, 'name' => $marketer->name, 'business_name' => $marketer->business_name],
            'commission_rate' => $rate,
            'totals' => $this->commissions->totals($marketer->id) + [
                'active_clients' => $clients->whereNull('ended_at')->count(),
                'orders_placed' => (int) $rows->sum('orders_placed'),
                'orders_delivered' => (int) $rows->sum('orders_delivered'),
                'sales_volume' => round((float) $rows->sum('sales_volume'), 2),
            ],
            'clients' => $rows,
            // Same worked example as the Partner Portal demo, with this marketer's rate.
            'formula' => [
                'supplier_price' => 500, 'admin_price' => 525, 'product_markup' => 25,
                'delivery_charge' => 130, 'courier_fee' => 80, 'delivery_margin' => 50,
                'platform_profit' => 75, 'rate' => $rate, 'commission' => round(75 * $rate / 100, 2),
            ],
        ]);
    }

    /** GET /marketer/commissions — the marketer's own commission lines. */
    public function myCommissions(Request $request): JsonResponse
    {
        return $this->ok('Commissions fetched', $this->commissionQuery($request, $request->user()->id)->paginate($this->perPage($request)));
    }

    /** GET /marketer/commission-payouts — the marketer's payouts. */
    public function myPayouts(Request $request): JsonResponse
    {
        return $this->ok('Payouts fetched', SettlementInvoice::where('type', SettlementInvoice::TYPE_MARKETER_COMMISSION)
            ->where('marketer_id', $request->user()->id)
            ->latest('id')->paginate($this->perPage($request)));
    }

    /* ======================= Admin ======================= */

    /** GET /admin/marketers/commissions — every marketer with rate and commission totals. */
    public function adminSummary(): JsonResponse
    {
        $totals = $this->commissions->totals();
        $clientCounts = MarketerClient::whereNull('ended_at')->selectRaw('marketer_user_id, COUNT(*) AS n')
            ->groupBy('marketer_user_id')->pluck('n', 'marketer_user_id');
        $empty = ['payable' => 0.0, 'invoiced' => 0.0, 'paid' => 0.0, 'earned_total' => 0.0, 'commissioned_orders' => 0];

        $marketers = User::where('user_type', 'marketer')
            ->select(['id', 'name', 'business_name', 'email', 'phone', 'banned', 'marketer_commission_rate'])
            ->orderBy('name')->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'business_name' => $u->business_name,
                'email' => $u->email,
                'phone' => $u->phone,
                'suspended' => (bool) $u->banned,
                'commission_rate' => $this->commissions->rateFor($u),
                'custom_rate' => $u->marketer_commission_rate !== null,
                'active_clients' => (int) ($clientCounts[$u->id] ?? 0),
                // Where they want to be paid (marketer panel → Earnings → payment accounts).
                'payment_accounts' => app(MarketerPaymentAccountController::class)->accountsOf($u->id),
            ] + ($totals[$u->id] ?? $empty));

        return $this->ok('Marketer commissions', [
            'default_rate' => (float) config('marketer.commission_rate', 20),
            'marketers' => $marketers,
            'totals' => [
                'payable' => round($marketers->sum('payable'), 2),
                'invoiced' => round($marketers->sum('invoiced'), 2),
                'paid' => round($marketers->sum('paid'), 2),
            ],
        ]);
    }

    /** GET /admin/marketers/commission-payouts/{invoiceId} — the payout invoice with its lines. */
    public function adminPayoutDetails(int $invoiceId): JsonResponse
    {
        $invoice = SettlementInvoice::where('type', SettlementInvoice::TYPE_MARKETER_COMMISSION)->find($invoiceId);

        return $invoice ? $this->ok('Commission payout', $this->payoutDocument($invoice))
            : response()->json(['status' => 'failed', 'message' => 'Commission payout not found', 'errors' => null], 404);
    }

    /** GET /marketer/commission-payouts/{invoiceId} — own payouts only. */
    public function myPayoutDetails(Request $request, int $invoiceId): JsonResponse
    {
        $invoice = SettlementInvoice::where('type', SettlementInvoice::TYPE_MARKETER_COMMISSION)
            ->where('marketer_id', $request->user()->id)->find($invoiceId);

        return $invoice ? $this->ok('Commission payout', $this->payoutDocument($invoice))
            : response()->json(['status' => 'failed', 'message' => 'Commission payout not found', 'errors' => null], 404);
    }

    /** GET /admin/marketers/{id}/commissions — lines of one marketer (also used for the sheet download). */
    public function adminCommissions(Request $request, int $id): JsonResponse
    {
        User::where('user_type', 'marketer')->findOrFail($id);

        return $this->ok('Commissions fetched', $this->commissionQuery($request, $id)->paginate($this->perPage($request)));
    }

    /** PATCH /admin/marketers/{id}/commission-rate {rate|null} — new commissions only. */
    public function setRate(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['rate' => ['present', 'nullable', 'numeric', 'min:0', 'max:100']]);
        $marketer = User::where('user_type', 'marketer')->findOrFail($id);
        $marketer->update(['marketer_commission_rate' => $data['rate'] === null ? null : round((float) $data['rate'], 2)]);

        return $this->ok('Commission rate saved. It applies to orders delivered from now on.', [
            'id' => $marketer->id, 'commission_rate' => $this->commissions->rateFor($marketer->fresh()),
        ]);
    }

    /** GET /admin/marketers/commission-payouts?marketer_id=&status= */
    public function adminPayouts(Request $request): JsonResponse
    {
        $q = SettlementInvoice::with(['marketer:id,name,business_name,phone', 'creator:id,name', 'payer:id,name'])
            ->where('type', SettlementInvoice::TYPE_MARKETER_COMMISSION)->latest('id');
        if ($request->filled('marketer_id')) {
            $q->where('marketer_id', (int) $request->input('marketer_id'));
        }
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $q->where('status', $request->input('status'));
        }

        return $this->ok('Commission payouts', $q->paginate($this->perPage($request)));
    }

    /** POST /admin/marketers/{id}/commission-payouts */
    public function createPayout(Request $request, int $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate($this->paymentRules() + [
                'commission_ids' => ['nullable', 'array'],
                'commission_ids.*' => ['integer'],
                'status' => ['nullable', 'in:paid,pending'],
                'note' => ['nullable', 'string', 'max:1000'],
            ]);

            return $this->ok('Commission payout created',
                $this->commissions->createPayout($id, $data['commission_ids'] ?? null, $data, $this->adminId($request)), 201);
        });
    }

    /** POST /admin/marketers/commission-payouts/{invoiceId}/pay */
    public function pay(Request $request, int $invoiceId): JsonResponse
    {
        return $this->run(fn () => $this->ok('Commission paid',
            $this->commissions->pay($invoiceId, $request->validate($this->paymentRules()), $this->adminId($request))));
    }

    /** POST /admin/marketers/commission-payouts/{invoiceId}/cancel */
    public function cancel(Request $request, int $invoiceId): JsonResponse
    {
        return $this->run(function () use ($request, $invoiceId) {
            $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

            return $this->ok('Payout cancelled', $this->commissions->cancel($invoiceId, $data['reason'] ?? null, $this->adminId($request)));
        });
    }

    /* ======================= helpers ======================= */

    /**
     * Invoice + marketer + one row per commission line. Lines come from the ids saved on the
     * invoice, so a cancelled payout still shows what it held.
     */
    private function payoutDocument(SettlementInvoice $invoice): array
    {
        $invoice->loadMissing(['marketer:id,name,business_name,phone,email', 'creator:id,name', 'payer:id,name']);
        $raw = $invoice->getAttributes()['orders_breakdown'] ?? '[]';
        $snapshot = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
        $lines = MarketerCommission::with(['order:id,order_number,created_at', 'client:id,dropshipper_user_id', 'client.dropshipper:id,name,business_name'])
            ->whereIn('id', array_column($snapshot, 'commission_id'))->orderBy('id')->get()
            ->map(fn (MarketerCommission $c) => [
                'id' => $c->id,
                'type' => $c->type,
                'order_number' => $c->order?->order_number ?? "#{$c->order_id}",
                'order_date' => $c->order?->created_at,
                'client' => $c->client?->dropshipper?->business_name ?: $c->client?->dropshipper?->name,
                'product_markup' => $c->product_markup,
                'delivery_margin' => $c->delivery_margin,
                'base_amount' => $c->base_amount,
                'rate' => $c->rate,
                'amount' => $c->amount,
                'earned_at' => $c->earned_at,
            ])->values();

        return [
            'invoice' => collect($invoice->only([
                'id', 'invoice_number', 'status', 'payment_method', 'trx_id', 'receiver_account', 'note',
                'total_amount_owed', 'total_platform_net_profit', 'orders_count', 'paid_at', 'created_at',
                'rejected_at', 'rejection_reason',
            ]))->all(),
            'marketer' => $invoice->marketer?->only(['id', 'name', 'business_name', 'phone', 'email']),
            'created_by' => $invoice->creator?->name,
            'paid_by' => $invoice->payer?->name,
            'lines' => $lines,
            'totals' => [
                'commission' => round((float) $lines->where('type', MarketerCommission::TYPE_COMMISSION)->sum('amount'), 2),
                'taken_back' => round((float) $lines->where('type', MarketerCommission::TYPE_CLAWBACK)->sum('amount'), 2),
                'payout' => round((float) $invoice->total_amount_owed, 2),
            ],
        ];
    }

    private function commissionQuery(Request $request, int $marketerId)
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:all,payable,invoiced,paid,reversed'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'client_id' => ['nullable', 'integer'],
        ]);
        $q = MarketerCommission::with([
            'order:id,order_number,status,total,created_at',
            'client:id,dropshipper_user_id',
            'client.dropshipper:id,name,business_name',
            'payoutInvoice:id,invoice_number,status,paid_at',
        ])->where('marketer_user_id', $marketerId)->latest('id');

        match ($data['status'] ?? 'all') {
            'payable' => $q->payable(),
            'invoiced' => $q->where('status', MarketerCommission::STATUS_EARNED)->whereNotNull('payout_invoice_id'),
            'paid' => $q->where('status', MarketerCommission::STATUS_PAID),
            'reversed' => $q->where('status', MarketerCommission::STATUS_REVERSED),
            default => null,
        };
        if (!empty($data['from'])) {
            $q->whereDate('earned_at', '>=', $data['from']);
        }
        if (!empty($data['to'])) {
            $q->whereDate('earned_at', '<=', $data['to']);
        }
        if (!empty($data['client_id'])) {
            $q->where('marketer_client_id', (int) $data['client_id']);
        }

        return $q;
    }

    private function paymentRules(): array
    {
        return [
            'payment_method' => ['nullable', 'in:' . implode(',', MarketerCommissionService::PAYMENT_METHODS)],
            'trx_id' => ['nullable', 'string', 'max:100'],
            'receiver_account' => ['nullable', 'string', 'max:100'],
        ];
    }

    private function run(callable $fn): JsonResponse
    {
        try {
            return DB::transaction(fn () => $fn());
        } catch (ValidationException $e) {
            return response()->json(['status' => 'failed', 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'failed', 'message' => $e->getMessage(), 'errors' => null], 422);
        }
    }

    private function adminId(Request $request): ?int
    {
        return ($request->attributes->get('api_user') ?? $request->user())?->id;
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->input('per_page', 20)));
    }

    private function ok(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data], $status);
    }
}
