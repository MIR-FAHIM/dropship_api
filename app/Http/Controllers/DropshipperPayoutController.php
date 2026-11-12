<?php

namespace App\Http\Controllers;

use App\Models\SettlementInvoice;
use App\Models\User;
use App\Service\DropshipperPayoutService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Dropshipper payout invoices: pay one dropshipper's profit for many orders with
 * a single invoice. Admins create / pay / cancel; a dropshipper sees their own.
 */
class DropshipperPayoutController extends Controller
{
    public function __construct(private readonly DropshipperPayoutService $payouts)
    {
    }

    /**
     * GET /dropshipper-payouts/payable-orders?dropshipper_id=
     * Admin: this dropshipper's unpaid-profit orders, each payable or not (with reason).
     */
    public function payableOrders(Request $request)
    {
        $validated = $request->validate(['dropshipper_id' => ['required', 'integer', 'exists:users,id']]);
        $dropshipper = User::select('id', 'name', 'phone', 'email')->find($validated['dropshipper_id']);

        return $this->success('Payable orders fetched', ['dropshipper' => $dropshipper] + $this->payouts->payableOrders((int) $validated['dropshipper_id']));
    }

    /**
     * POST /dropshipper-payouts
     * Admin creates an invoice. status=paid (default) pays it now; status=pending only issues it.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate($this->paymentRules() + [
                'dropshipper_id' => ['required', 'integer', 'exists:users,id'],
                'order_ids' => ['required', 'array', 'min:1'],
                'order_ids.*' => ['integer', 'distinct'],
                'status' => ['nullable', Rule::in(['pending', 'paid'])],
            ]);

            if (($validated['status'] ?? 'paid') === 'paid' && !$request->boolean('payout_review_confirmed')) {
                return $this->failed('Confirm you reviewed the dropshipper balance, withdraw requests and advance payments before paying.', ['code' => 'review_required'], 422);
            }

            $invoice = $this->payouts->create((int) $validated['dropshipper_id'], $validated['order_ids'], $validated, $this->userId($request));

            return $this->success('Payout invoice created', $invoice->load(['dropshipper:id,name,phone,email', 'creator:id,name', 'payer:id,name']), 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (InvalidArgumentException $e) {
            return $this->failed($e->getMessage(), null, 422);
        } catch (\Throwable $e) {
            report($e);

            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /** POST /dropshipper-payouts/{id}/pay — admin pays an issued invoice. */
    public function pay(Request $request, $id)
    {
        try {
            $validated = $request->validate($this->paymentRules());
            if (!$request->boolean('payout_review_confirmed')) {
                return $this->failed('Confirm you reviewed the dropshipper balance, withdraw requests and advance payments before paying.', ['code' => 'review_required'], 422);
            }

            $invoice = $this->payouts->pay((int) $id, $validated, $this->userId($request));

            return $this->success('Payout invoice paid', $invoice);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (InvalidArgumentException $e) {
            return $this->failed($e->getMessage(), null, 422);
        } catch (\Throwable $e) {
            report($e);

            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /** POST /dropshipper-payouts/{id}/cancel — admin withdraws an unpaid invoice. */
    public function cancel(Request $request, $id)
    {
        try {
            $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
            $invoice = $this->payouts->cancel((int) $id, $validated['reason'] ?? null, $this->userId($request));

            return $this->success('Payout invoice cancelled', $invoice);
        } catch (InvalidArgumentException $e) {
            return $this->failed($e->getMessage(), null, 422);
        }
    }

    /**
     * GET /dropshipper-payouts
     * Admin: all payout invoices (filters: dropshipper_id, status, search, date_from, date_to).
     * Dropshipper: only their own.
     */
    public function index(Request $request)
    {
        $user = $request->attributes->get('api_user') ?? $request->user();
        $isAdmin = $this->isAdmin($user);

        $query = SettlementInvoice::with(['dropshipper:id,name,phone,email', 'creator:id,name', 'payer:id,name'])
            ->where('type', SettlementInvoice::TYPE_DROPSHIPPER_PAYOUT)
            ->orderByDesc('id');

        if ($isAdmin) {
            if ($request->filled('dropshipper_id')) {
                $query->where('dropshipper_id', (int) $request->get('dropshipper_id'));
            }
        } else {
            $query->where('dropshipper_id', $user?->id ?? 0);
        }

        if ($request->filled('status') && $request->get('status') !== 'all') {
            $query->where('status', $request->get('status'));
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->get('search'));
            $query->where(fn ($q) => $q->where('invoice_number', 'like', "%{$search}%")->orWhere('trx_id', 'like', "%{$search}%"));
        }
        foreach (['date_from' => 'startOfDay', 'date_to' => 'endOfDay'] as $param => $edge) {
            $value = (string) $request->get($param, '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $query->where('created_at', $param === 'date_from' ? '>=' : '<=', \Carbon\Carbon::createFromFormat('Y-m-d', $value, 'Asia/Dhaka')->{$edge}()->utc());
            }
        }

        $summaryQuery = clone $query;
        $invoices = $query->paginate(min(100, max(1, (int) $request->get('per_page', 20))));

        // The list doesn't need each invoice's full order breakdown.
        $invoices->getCollection()->each->makeHidden(['orders_breakdown']);

        return $this->success('Payout invoices fetched', [
            'invoices' => $invoices,
            'summary' => [
                'paid_total' => round((float) (clone $summaryQuery)->reorder()->where('status', SettlementInvoice::STATUS_PAID)->sum('total_dropshipper_payout'), 2),
                'pending_total' => round((float) (clone $summaryQuery)->reorder()->where('status', SettlementInvoice::STATUS_PENDING)->sum('total_dropshipper_payout'), 2),
            ],
        ]);
    }

    /** GET /dropshipper-payouts/{id} — admin, or the dropshipper it belongs to. */
    public function show(Request $request, $id)
    {
        $invoice = SettlementInvoice::with([
            'dropshipper:id,name,phone,email,address',
            'dropshipper.bankAccounts:id,user_id,bank_name,acc_name,type,account_no,branch,route,address,is_active,payment_method_id',
            'creator:id,name',
            'payer:id,name',
        ])
            ->where('type', SettlementInvoice::TYPE_DROPSHIPPER_PAYOUT)
            ->where(fn ($q) => is_numeric($id) ? $q->where('id', $id) : $q->where('invoice_number', $id))
            ->first();

        if (!$invoice) {
            return $this->failed('Payout invoice not found', null, 404);
        }

        $user = $request->attributes->get('api_user') ?? $request->user();
        if (!$this->isAdmin($user) && (int) $invoice->dropshipper_id !== (int) ($user?->id ?? 0)) {
            return $this->failed('You are not allowed to view this invoice.', null, 403);
        }

        return $this->success('Payout invoice fetched', $invoice);
    }

    private function success($message, $data = null, int $code = 200)
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json(['status' => 'failed', 'message' => $message, 'errors' => $errors], $code);
    }

    private function paymentRules(): array
    {
        return [
            'payment_method' => ['nullable', Rule::in(DropshipperPayoutService::PAYMENT_METHODS)],
            'trx_id' => ['nullable', 'string', 'max:100'],
            'receiver_account' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function userId(Request $request): ?int
    {
        return ($request->attributes->get('api_user') ?? $request->user())?->id;
    }

    private function isAdmin($user): bool
    {
        if (!$user || (int) ($user->banned ?? 0) === 1) {
            return false;
        }

        return (bool) array_intersect(
            [strtolower((string) ($user->user_type ?? '')), strtolower((string) ($user->role ?? ''))],
            ['admin', 'superadmin']
        );
    }
}
