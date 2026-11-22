<?php

namespace App\Http\Controllers;

use App\Models\MarketerClient;
use App\Models\PartnerProductRequirement as Req;
use App\Service\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Partner Portal F4 — product sourcing requests.
 * Marketer: send a request (product + photo + campaign details + deadline), follow its status.
 * Admin (sourcing team): see requests by urgency, mark Checking / Confirmed (with price) / Rejected.
 */
class PartnerSourcingController extends Controller
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /* ======================= Marketer ======================= */

    /** GET /marketer/requirements?status=all|open|confirmed|rejected */
    public function index(Request $request): JsonResponse
    {
        $partnerId = $request->user()->id;
        $q = Req::with(['client:id,dropshipper_user_id', 'client.dropshipper:id,name,business_name', 'linkedProduct:id,name'])
            ->where('partner_id', $partnerId)->latest('id');
        $this->filterStatus($q, (string) $request->input('status', 'all'));

        return $this->ok('Sourcing requests', [
            'requests' => $q->paginate($this->perPage($request)),
            'counts' => $this->counts(Req::where('partner_id', $partnerId)),
        ]);
    }

    /** POST /marketer/requirements (multipart) */
    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'product_name' => ['required', 'string', 'max:255'],
                'product_image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
                'campaign_platform' => ['required', Rule::in(Req::PLATFORMS)],
                'daily_budget' => ['required', 'numeric', 'min:1', 'max:100000'],
                'campaign_status' => ['required', Rule::in(Req::CAMPAIGN_STATUSES)],
                'confirmation_deadline' => ['required', Rule::in(Req::DEADLINES)],
                'deadline_days' => ['required_if:confirmation_deadline,days', 'nullable', 'integer', 'min:1', 'max:60'],
                'deadline_date' => ['required_if:confirmation_deadline,date', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
                'special_note' => ['nullable', 'string', 'max:2000'],
                'client_id' => ['nullable', 'integer'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'failed', 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }

        $partner = $request->user();
        $clientId = null;
        if (!empty($data['client_id'])) {
            $client = MarketerClient::where('marketer_user_id', $partner->id)->whereNull('ended_at')->find($data['client_id']);
            if (!$client) {
                return response()->json(['status' => 'failed', 'message' => 'That client is not connected to you.',
                    'errors' => ['client_id' => ['Choose one of your connected clients.']]], 422);
            }
            $clientId = $client->id;
        }

        $deadlineAt = match ($data['confirmation_deadline']) {
            '2h' => now()->addHours(2),
            '6h' => now()->addHours(6),
            '24h' => now()->addHours(24),
            'days' => now()->addDays((int) $data['deadline_days']),
            'date' => Carbon::createFromFormat('Y-m-d', $data['deadline_date'], 'Asia/Dhaka')->endOfDay()->utc(),
        };

        $path = $request->file('product_image')->store("partner-requirements/{$partner->id}", 'public');
        $req = Req::create([
            'partner_id' => $partner->id,
            'marketer_client_id' => $clientId,
            'product_name' => trim($data['product_name']),
            'product_image' => $path,
            'campaign_platform' => $data['campaign_platform'],
            'daily_budget_usd' => round((float) $data['daily_budget'], 2),
            'campaign_status' => $data['campaign_status'],
            'confirmation_deadline' => $data['confirmation_deadline'],
            'deadline_at' => $deadlineAt,
            'special_note' => $data['special_note'] ?? null,
            'status' => Req::STATUS_PENDING,
        ]);

        // Strict timelines: tell the sourcing team at once; 2/6-hour requests are flagged urgent.
        $urgent = in_array($req->confirmation_deadline, ['2h', '6h'], true);
        $who = $partner->business_name ?: $partner->name;
        $this->notifications->createAdminNotifications([
            'title' => ($urgent ? '🔴 URGENT ' : '') . 'Partner sourcing request',
            'subtitle' => "{$who}: {$req->product_name} — answer by " . $req->deadline_at->timezone('Asia/Dhaka')->format('d M, h:i A'),
            'created_by' => $partner->id, 'is_seen' => false, 'type' => 'sourcing', 'is_active' => true, 'image' => null, 'module' => 'partner_sourcing',
        ]);

        return $this->ok('Sourcing request submitted', $req->fresh(), 201);
    }

    /** DELETE /marketer/requirements/{id} — withdraw a request that is still pending. */
    public function withdraw(Request $request, int $id): JsonResponse
    {
        $req = Req::where('partner_id', $request->user()->id)->find($id);
        if (!$req) {
            return response()->json(['status' => 'failed', 'message' => 'Request not found', 'errors' => null], 404);
        }
        if ($req->status !== Req::STATUS_PENDING) {
            return response()->json(['status' => 'failed', 'message' => 'Only a pending request can be withdrawn.', 'errors' => null], 422);
        }
        $req->update(['status' => Req::STATUS_WITHDRAWN]);

        return $this->ok('Request withdrawn', $req->fresh());
    }

    /* ======================= Admin ======================= */

    /** GET /admin/sourcing-requests?status=open|all|pending|checking|confirmed|rejected|withdrawn&search= */
    public function adminIndex(Request $request): JsonResponse
    {
        $q = Req::with([
            'partner:id,name,business_name,phone',
            'client:id,dropshipper_user_id', 'client.dropshipper:id,name,business_name',
            'linkedProduct:id,name,sku', 'handler:id,name',
        ]);
        $status = (string) $request->input('status', 'open');
        $this->filterStatus($q, $status);
        if ($request->filled('search')) {
            $s = trim((string) $request->input('search'));
            $q->where(fn ($w) => $w->where('product_name', 'like', "%{$s}%")
                ->orWhereHas('partner', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('business_name', 'like', "%{$s}%")));
        }
        // Open work first, soonest deadline on top; answered ones newest first.
        $q->orderByRaw("CASE WHEN status IN ('pending', 'checking') THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN status IN ('pending', 'checking') THEN deadline_at END ASC")
            ->latest('id');

        $base = Req::query();

        return $this->ok('Sourcing requests', [
            'requests' => $q->paginate($this->perPage($request)),
            'counts' => $this->counts($base) + [
                'overdue' => (clone $base)->whereIn('status', Req::OPEN_STATUSES)->where('deadline_at', '<', now())->count(),
                'urgent' => (clone $base)->whereIn('status', Req::OPEN_STATUSES)->where(fn ($w) => $w
                    ->whereIn('confirmation_deadline', ['2h', '6h'])->orWhere('deadline_at', '<=', now()->addHours(6)))->count(),
            ],
        ]);
    }

    /** PATCH /admin/sourcing-requests/{id} {status, quoted_price_bdt, admin_note, linked_product_id} */
    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $req = Req::find($id);
        if (!$req) {
            return response()->json(['status' => 'failed', 'message' => 'Request not found', 'errors' => null], 404);
        }
        try {
            $data = $request->validate([
                'status' => ['required', Rule::in([Req::STATUS_PENDING, Req::STATUS_CHECKING, Req::STATUS_CONFIRMED, Req::STATUS_REJECTED])],
                'quoted_price_bdt' => ['required_if:status,confirmed', 'nullable', 'numeric', 'min:1', 'max:10000000'],
                'admin_note' => ['required_if:status,rejected', 'nullable', 'string', 'max:2000'],
                'linked_product_id' => ['nullable', 'integer', 'exists:products,id'],
            ], [
                'quoted_price_bdt.required_if' => 'Enter the confirmed price (incl. packaging).',
                'admin_note.required_if' => 'Tell the partner why it was rejected.',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'failed', 'message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        }
        if ($req->status === Req::STATUS_WITHDRAWN) {
            return response()->json(['status' => 'failed', 'message' => 'The partner withdrew this request.', 'errors' => null], 422);
        }

        $previous = $req->status;
        $answered = in_array($data['status'], [Req::STATUS_CONFIRMED, Req::STATUS_REJECTED], true);
        $req->update([
            'status' => $data['status'],
            'quoted_price_bdt' => $data['status'] === Req::STATUS_CONFIRMED ? round((float) $data['quoted_price_bdt'], 2) : $req->quoted_price_bdt,
            'admin_note' => array_key_exists('admin_note', $data) ? $data['admin_note'] : $req->admin_note,
            'linked_product_id' => array_key_exists('linked_product_id', $data) ? $data['linked_product_id'] : $req->linked_product_id,
            'handled_by' => ($request->attributes->get('api_user') ?? $request->user())?->id,
            'responded_at' => $answered ? now() : $req->responded_at,
        ]);

        if ($previous !== $req->status) {
            $text = match ($req->status) {
                Req::STATUS_CHECKING => "We're checking \"{$req->product_name}\" now.",
                Req::STATUS_CONFIRMED => "\"{$req->product_name}\" confirmed at ৳" . number_format((float) $req->quoted_price_bdt, 2) . ' (incl. packaging).',
                Req::STATUS_REJECTED => "\"{$req->product_name}\" can't be sourced: " . ($req->admin_note ?: 'see the note'),
                default => "\"{$req->product_name}\" is pending again.",
            };
            $this->notifications->createNotificationSafely([
                'title' => 'Sourcing request update', 'subtitle' => $text, 'created_by' => $req->handled_by, 'send_to' => $req->partner_id,
                'is_seen' => false, 'type' => 'sourcing', 'is_active' => true, 'image' => null, 'module' => 'partner_sourcing',
            ]);
        }

        return $this->ok('Sourcing request updated', $req->fresh(['partner:id,name,business_name,phone', 'linkedProduct:id,name,sku', 'handler:id,name']));
    }

    /* ======================= helpers ======================= */

    private function filterStatus($q, string $status): void
    {
        match ($status) {
            'open' => $q->whereIn('status', Req::OPEN_STATUSES),
            'pending', 'checking', 'confirmed', 'rejected', 'withdrawn' => $q->where('status', $status),
            default => null,
        };
    }

    private function counts($query): array
    {
        $rows = (clone $query)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $c = fn (string $s) => (int) ($rows[$s] ?? 0);

        return [
            'all' => (int) $rows->sum(),
            'open' => $c('pending') + $c('checking'),
            'pending' => $c('pending'), 'checking' => $c('checking'), 'confirmed' => $c('confirmed'),
            'rejected' => $c('rejected'), 'withdrawn' => $c('withdrawn'),
        ];
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
