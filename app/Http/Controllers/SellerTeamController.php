<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Service\SellerTeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Passive Income phase 1 — seller team (referral) APIs.
 *
 * Public:      GET  /seller-team/code/{code}                 → is this code valid, whose team (for the sign-up form)
 * Dropshipper: GET  /seller-team/summary                     → my progress to 10 orders, my code, my upline, team size per level
 *              GET  /seller-team/members?level=1..4          → members of one level
 * Admin:       GET  /admin/seller-team/sellers               → dropshippers with team size, filters
 *              GET  /admin/seller-team/users/{id}            → one dropshipper: uplines, team per level, change log
 *              PUT  /admin/seller-team/users/{id}/upline     → set / clear upline (reason required)
 *              POST /admin/seller-team/users/{id}/verify     → make verified seller (gives code)
 *              POST /admin/seller-team/users/{id}/unverify   → take verification away (reason required)
 */
class SellerTeamController extends Controller
{
    public function __construct(private readonly SellerTeamService $team)
    {
    }

    private function me(Request $request): User
    {
        return $request->attributes->get('api_user') ?? $request->user();
    }

    private function ok(string $message, $data = null): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data]);
    }

    private function fail(string $message, int $code = 422, $errors = null): JsonResponse
    {
        return response()->json(['status' => 'failed', 'message' => $message, 'errors' => $errors], $code);
    }

    /* ------------------------------------------------------------ public */

    public function checkCode(string $code): JsonResponse
    {
        $seller = $this->team->sellerForCode($code);

        return $seller
            ? $this->ok('Valid seller code', ['valid' => true, 'seller_name' => $this->shortName($seller->name)])
            : $this->ok('Invalid seller code', ['valid' => false]);
    }

    /* ------------------------------------------------------- dropshipper */

    public function summary(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $this->team->checkVerification($me);
        $me->refresh();

        $ids = $this->team->teamIds($me->id);
        $upline = $me->referred_by ? User::find($me->referred_by) : null;
        $delivered = $this->team->deliveredOrders($me->id);

        return $this->ok('Seller team', [
            'verified' => $this->team->isVerified($me),
            'verified_at' => $me->seller_verified_at?->toIso8601String(),
            'seller_code' => $this->team->isVerified($me) ? $me->seller_code : null,
            'delivered_orders' => $delivered,
            'orders_to_verify' => SellerTeamService::ORDERS_TO_VERIFY,
            'orders_left' => max(0, SellerTeamService::ORDERS_TO_VERIFY - $delivered),
            'upline' => $upline ? ['name' => $this->shortName($upline->name), 'joined_at' => $me->referred_at?->toIso8601String()] : null,
            'levels' => collect($ids)->map(fn ($l, $level) => ['level' => $level, 'members' => count($l)])->values(),
            'team_size' => collect($ids)->sum(fn ($l) => count($l)),
        ]);
    }

    public function members(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $level = max(1, min(SellerTeamService::LEVELS, (int) $request->query('level', 1)));
        $ids = $this->team->teamIds($me->id)[$level] ?? [];

        $page = User::whereIn('id', $ids)->orderByDesc('referred_at')->orderByDesc('id')
            ->paginate(max(1, min(50, (int) $request->query('per_page', 20))), ['id', 'name', 'phone', 'referred_by', 'referred_at', 'created_at', 'banned', 'seller_verified_at', 'seller_code']);
        $users = collect($page->items());
        $delivered = $this->deliveredCounts($users->pluck('id')->all());
        $via = $level > 1 ? User::whereIn('id', $users->pluck('referred_by')->filter()->unique())->pluck('name', 'id') : collect();

        return $this->ok("Team level {$level}", [
            'level' => $level,
            'members' => $users->map(fn (User $u) => [
                'id' => $u->id,
                // Direct members: name + masked phone. Deeper levels: first name only (their privacy).
                'name' => $level === 1 ? $u->name : $this->shortName($u->name),
                'phone' => $level === 1 ? $this->maskPhone($u->phone) : null,
                'joined_at' => ($u->referred_at ?? $u->created_at)?->toIso8601String(),
                'delivered_orders' => (int) ($delivered[$u->id] ?? 0),
                'verified' => $this->team->isVerified($u),
                'active' => (int) $u->banned === 0,
                'via' => $level > 1 ? $this->shortName($via[$u->referred_by] ?? '') : null,
            ])->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }

    /* ------------------------------------------------------------- admin */

    public function adminSellers(Request $request): JsonResponse
    {
        $q = User::where('user_type', 'dropshipper')
            ->withCount(['referrals as direct_members'])
            ->when($request->query('search'), function ($q, $s) {
                $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%")->orWhere('seller_code', strtoupper($s))->orWhere('id', (int) $s));
            })
            ->when($request->query('filter') === 'verified', fn ($q) => $q->whereNotNull('seller_verified_at'))
            ->when($request->query('filter') === 'has_team', fn ($q) => $q->has('referrals'))
            ->when($request->query('filter') === 'in_team', fn ($q) => $q->whereNotNull('referred_by'))
            ->orderByDesc('direct_members')->orderByDesc('id');

        $page = $q->paginate(max(1, min(100, (int) $request->query('per_page', 25))),
            ['id', 'name', 'phone', 'email', 'banned', 'referred_by', 'seller_code', 'seller_verified_at', 'created_at']);
        $users = collect($page->items());
        $delivered = $this->deliveredCounts($users->pluck('id')->all());
        $uplines = User::whereIn('id', $users->pluck('referred_by')->filter()->unique())->get(['id', 'name', 'seller_code'])->keyBy('id');

        return $this->ok('Seller team — dropshippers', [
            'rows' => $users->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'email' => $u->email, 'active' => (int) $u->banned === 0,
                'verified' => $this->team->isVerified($u), 'seller_code' => $u->seller_code,
                'delivered_orders' => (int) ($delivered[$u->id] ?? 0),
                'direct_members' => (int) $u->direct_members,
                'upline' => ($up = $uplines[$u->referred_by] ?? null) ? ['id' => $up->id, 'name' => $up->name, 'seller_code' => $up->seller_code] : null,
            ])->all(),
            'counts' => [
                'verified' => User::where('user_type', 'dropshipper')->whereNotNull('seller_verified_at')->count(),
                'in_a_team' => User::where('user_type', 'dropshipper')->whereNotNull('referred_by')->count(),
            ],
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }

    public function adminUser(int $id): JsonResponse
    {
        $u = User::where('user_type', 'dropshipper')->find($id);
        if (!$u) {
            return $this->fail('Dropshipper not found', 404);
        }
        $ids = $this->team->teamIds($u->id);
        $all = collect($ids)->flatten()->all();
        $people = User::whereIn('id', $all)->get(['id', 'name', 'phone', 'referred_by', 'referred_at', 'banned', 'seller_code', 'seller_verified_at'])->keyBy('id');
        $delivered = $this->deliveredCounts(array_merge($all, [$u->id]));
        $logs = DB::table('seller_team_logs')->where('user_id', $u->id)->orderByDesc('id')->limit(50)->get();
        $names = User::whereIn('id', $logs->pluck('old_upline_id')->merge($logs->pluck('new_upline_id'))->merge($logs->pluck('actor_id'))->filter()->unique())->pluck('name', 'id');

        return $this->ok('Seller team — dropshipper', [
            'user' => [
                'id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'active' => (int) $u->banned === 0,
                'verified' => $this->team->isVerified($u), 'seller_code' => $u->seller_code,
                'verified_at' => $u->seller_verified_at?->toIso8601String(),
                'delivered_orders' => (int) ($delivered[$u->id] ?? 0), 'orders_to_verify' => SellerTeamService::ORDERS_TO_VERIFY,
            ],
            'uplines' => $this->team->uplines($u)->map(fn ($c) => ['level' => $c['level'], 'id' => $c['user']->id, 'name' => $c['user']->name,
                'seller_code' => $c['user']->seller_code, 'verified' => $this->team->isVerified($c['user'])])->values(),
            'levels' => collect($ids)->map(fn ($l, $level) => [
                'level' => $level,
                'members' => collect($l)->map(fn ($mid) => ($m = $people[$mid] ?? null) ? [
                    'id' => $m->id, 'name' => $m->name, 'phone' => $m->phone, 'active' => (int) $m->banned === 0,
                    'verified' => $this->team->isVerified($m), 'delivered_orders' => (int) ($delivered[$m->id] ?? 0),
                    'upline_id' => $m->referred_by, 'joined_at' => $m->referred_at?->toIso8601String(),
                ] : null)->filter()->values(),
            ])->values(),
            'logs' => $logs->map(fn ($l) => [
                'action' => $l->action, 'seller_code' => $l->seller_code, 'reason' => $l->reason, 'at' => $l->created_at,
                'old_upline' => $l->old_upline_id ? ($names[$l->old_upline_id] ?? "#{$l->old_upline_id}") : null,
                'new_upline' => $l->new_upline_id ? ($names[$l->new_upline_id] ?? "#{$l->new_upline_id}") : null,
                'by' => $l->actor_id ? ($names[$l->actor_id] ?? "#{$l->actor_id}") : 'System',
            ]),
        ]);
    }

    public function adminSetUpline(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'upline_id' => ['nullable', 'integer'],
            'seller_code' => ['nullable', 'string', 'max:20'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $u = User::where('user_type', 'dropshipper')->find($id);
        if (!$u) {
            return $this->fail('Dropshipper not found', 404);
        }
        $upline = null;
        if (!empty($data['seller_code'])) {
            $upline = User::where('user_type', 'dropshipper')->where('seller_code', strtoupper(trim($data['seller_code'])))->first();
        } elseif (!empty($data['upline_id'])) {
            $upline = User::where('user_type', 'dropshipper')->find($data['upline_id']);
        }
        if ((!empty($data['seller_code']) || !empty($data['upline_id'])) && !$upline) {
            return $this->fail('Upline dropshipper not found', 404);
        }
        try {
            $this->team->changeUpline($u, $upline, $this->me($request)->id, $data['reason']);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->adminUser($u->id);
    }

    public function adminVerify(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $u = User::where('user_type', 'dropshipper')->find($id);
        if (!$u) {
            return $this->fail('Dropshipper not found', 404);
        }
        $this->team->verify($u, $this->me($request)->id, $data['reason'] ?? 'Verified by admin');

        return $this->adminUser($u->id);
    }

    public function adminUnverify(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $u = User::where('user_type', 'dropshipper')->find($id);
        if (!$u) {
            return $this->fail('Dropshipper not found', 404);
        }
        $this->team->unverify($u, $this->me($request)->id, $data['reason']);

        return $this->adminUser($u->id);
    }

    /* ----------------------------------------------------------- helpers */

    private function deliveredCounts(array $userIds): array
    {
        if (!$userIds) {
            return [];
        }

        return Order::whereIn('user_id', $userIds)
            ->where(fn ($q) => $q->where('status', 9)->orWhereHas('orderStatus', fn ($s) => $s->whereIn('type', ['delivered', 'completed'])))
            ->selectRaw('user_id, COUNT(*) AS n')->groupBy('user_id')->pluck('n', 'user_id')->all();
    }

    /** "Rahim Uddin Ahmed" → "Rahim U." */
    private function shortName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        if (!$parts || $parts[0] === '') {
            return '';
        }

        return $parts[0] . (isset($parts[1]) ? ' ' . mb_substr($parts[1], 0, 1) . '.' : '');
    }

    private function maskPhone(?string $phone): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $phone);

        return strlen($d) >= 6 ? substr($d, 0, 3) . str_repeat('•', strlen($d) - 6) . substr($d, -3) : null;
    }
}
