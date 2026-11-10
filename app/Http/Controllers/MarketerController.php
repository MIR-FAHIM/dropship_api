<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\LoginSuccessLog;
use App\Models\MarketerClient;
use App\Models\MarketerInvitation;
use App\Models\Order;
use App\Models\ProductClicks;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class MarketerController extends Controller
{
    private const CONSENT_TEXT = 'The marketer can see your name, onboarding date, and order counts and statuses attributed to this relationship. Shopper details and wallet information are not shared. You can end the relationship without losing your account.';

    public function create(Request $request): JsonResponse
    {
        $request->merge(['email' => $this->normalizeEmail($request->input('email'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'business_name' => ['nullable', 'string', 'max:255'],
        ]);

        $marketer = User::create($data + [
            'user_type' => 'marketer',
            'role' => 'marketer',
            'banned' => 0,
            'password' => Hash::make(Str::random(64)),
        ]);

        return $this->success('Marketer approved. Use the email password reset flow to set a password.', $marketer, 201);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->success('Marketers fetched', User::where('user_type', 'marketer')
            ->select(['id', 'name', 'business_name', 'email', 'banned', 'created_at'])
            ->latest('id')->paginate($this->perPage($request)));
    }

    public function setSuspended(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['suspended' => ['required', 'boolean']]);
        $marketer = User::where('user_type', 'marketer')->findOrFail($id);
        $marketer->update(['banned' => (int) $data['suspended']]);

        return $this->success('Marketer status updated', ['id' => $marketer->id, 'suspended' => (bool) $marketer->banned]);
    }

    public function invite(Request $request): JsonResponse
    {
        $request->merge(['email' => $this->normalizeEmail($request->input('email'))]);
        $data = $request->validate(['email' => ['required', 'email', 'max:191']]);
        $marketer = $request->user();
        $code = Str::random(48);

        $invitation = MarketerInvitation::create([
            'marketer_user_id' => $marketer->id,
            'email' => $data['email'],
            'token_hash' => hash('sha256', $code),
            'expires_at' => now()->addDays(7),
        ]);

        try {
            Mail::raw("{$marketer->name} invited you to connect your ResellerBrain dropshipper account. Sign in with this email and use this invitation code: {$code}\n\nThis code expires in 7 days. Connecting is optional.", function ($message) use ($data) {
                $message->to($data['email'])->subject('ResellerBrain marketer invitation');
            });
        } catch (\Throwable) {
            $invitation->update(['revoked_at' => now()]);

            return response()->json(['status' => 'failed', 'message' => 'Invitation email could not be sent'], 503);
        }

        return $this->success('Invitation emailed', $invitation, 201);
    }

    public function invitations(Request $request): JsonResponse
    {
        return $this->success('Invitations fetched', MarketerInvitation::where('marketer_user_id', $request->user()->id)
            ->latest('id')->paginate($this->perPage($request)));
    }

    public function revokeInvitation(Request $request, int $id): JsonResponse
    {
        $updated = MarketerInvitation::whereKey($id)->where('marketer_user_id', $request->user()->id)
            ->whereNull('revoked_at')->whereNull('marketer_client_id')->update(['revoked_at' => now()]);
        abort_unless($updated, 404, 'Pending invitation not found');

        return $this->success('Invitation revoked');
    }

    public function preview(Request $request): JsonResponse
    {
        $this->requireDropshipper($request);
        $data = $request->validate(['code' => ['required', 'string', 'alpha_num:ascii', 'size:48']]);
        $invite = $this->pendingInvitation($data['code']);
        $marketer = User::where('user_type', 'marketer')->where('banned', 0)->find($invite->marketer_user_id);
        abort_unless($marketer && $this->normalizeEmail($request->user()->email) === $invite->email, 404, 'Invitation unavailable');

        return $this->success('Invitation found', [
            'marketer_name' => $marketer->name,
            'business_name' => $marketer->business_name,
            'expires_at' => $invite->expires_at,
            'consent_version' => MarketerClient::CONSENT_VERSION,
            'consent_text' => self::CONSENT_TEXT,
        ]);
    }

    public function accept(Request $request): JsonResponse
    {
        $this->requireDropshipper($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'alpha_num:ascii', 'size:48'],
            'consent' => ['required', 'accepted'],
            'consent_version' => ['nullable', 'in:'.MarketerClient::CONSENT_VERSION],
        ]);

        $consentVersion = $data['consent_version'] ?? MarketerClient::CONSENT_VERSION;

        $client = DB::transaction(function () use ($request, $data, $consentVersion) {
            $invite = $this->pendingInvitation($data['code'], lock: true);
            $marketer = User::where('user_type', 'marketer')->where('banned', 0)->lockForUpdate()->find($invite->marketer_user_id);
            $dropshipper = User::where('user_type', 'dropshipper')->where('banned', 0)->lockForUpdate()->find($request->user()->id);
            abort_unless($marketer && $dropshipper && $this->normalizeEmail($dropshipper->email) === $invite->email, 404, 'Invitation unavailable');
            abort_if(MarketerClient::where('active_dropshipper_user_id', $dropshipper->id)->exists(), 409, 'Dropshipper already has an active marketer');

            $client = MarketerClient::create([
                'marketer_user_id' => $marketer->id,
                'dropshipper_user_id' => $dropshipper->id,
                'active_dropshipper_user_id' => $dropshipper->id,
                'consent_version' => $consentVersion,
                'accepted_at' => now(),
            ]);
            $invite->update(['marketer_client_id' => $client->id]);

            return $client;
        }, 3);

        return $this->success('Marketer relationship accepted', $client, 201);
    }

    public function adminClients(Request $request, int $id): JsonResponse
    {
        User::where('user_type', 'marketer')->findOrFail($id);

        return $this->success('Client relationships fetched', MarketerClient::where('marketer_user_id', $id)
            ->with('dropshipper:id,name')->latest('id')->paginate($this->perPage($request)));
    }

    public function endClient(Request $request, int $id): JsonResponse
    {
        if (!$request->routeIs('admin.marketers.clients.end')) {
            $this->requireDropshipper($request);
        }
        $query = MarketerClient::whereKey($id)->whereNotNull('active_dropshipper_user_id');
        if (!$request->routeIs('admin.marketers.clients.end')) {
            $query->where('dropshipper_user_id', $request->user()->id);
        }
        abort_unless($query->update([
            'active_dropshipper_user_id' => null,
            'ended_at' => now(),
            'ended_by' => $request->user()->id,
        ]), 404, 'Active client relationship not found');

        return $this->success('Client relationship ended');
    }

    public function clients(Request $request): JsonResponse
    {
        $marketerId = $request->user()->id;
        $perPage = $this->perPage($request);
        $search = trim((string) $request->input('search', ''));
        $period = (string) $request->input('period', 'all'); // '7d', '30d', '90d', 'all'
        $activity = (string) $request->input('activity', 'all'); // 'all', 'active', 'moderate', 'inactive'
        $minOrders = $request->filled('min_orders') ? (int) $request->input('min_orders') : null;
        $sortBy = (string) $request->input('sort_by', 'joined'); // 'clicks', 'orders', 'delivered', 'last_login', 'joined'
        $sortDirection = strtolower((string) $request->input('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        $allRelationships = MarketerClient::where('marketer_user_id', $marketerId)
            ->whereNotNull('active_dropshipper_user_id')
            ->with(['dropshipper' => function ($q) {
                $q->select('id', 'name', 'business_name', 'email');
            }])
            ->get();

        if ($allRelationships->isEmpty()) {
            return $this->success('Active clients fetched', [
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $perPage,
                'total' => 0,
                'from' => null,
                'to' => null,
                'summary' => [
                    'total_clients' => 0,
                    'total_delivered_orders' => 0,
                    'total_product_clicks' => 0,
                    'recently_active_clients' => 0,
                    'distribution' => [
                        'active' => 0,
                        'moderate' => 0,
                        'inactive' => 0,
                    ],
                ],
                'insights' => [
                    'top_performer' => null,
                    'high_interest_low_orders' => null,
                    'inactive_clients' => 0,
                ],
            ]);
        }

        $clientIds = $allRelationships->pluck('id')->all();
        $dropshipperIds = $allRelationships->pluck('dropshipper_user_id')->filter()->unique()->all();

        $periodDate = match ($period) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            default => null,
        };

        $clicksQuery = ProductClicks::whereIn('clicked_by', $dropshipperIds);
        if ($periodDate) {
            $clicksQuery->where('created_at', '>=', $periodDate);
        }
        $clicksMap = $clicksQuery->selectRaw('clicked_by, COUNT(*) as count')
            ->groupBy('clicked_by')
            ->pluck('count', 'clicked_by')
            ->all();

        $ordersQuery = Order::whereIn('marketer_client_id', $clientIds);
        if ($periodDate) {
            $ordersQuery->where('created_at', '>=', $periodDate);
        }
        $ordersStats = $ordersQuery->selectRaw(
            'marketer_client_id, '
            .'COUNT(*) as placed, '
            .'COUNT(CASE WHEN status IN (?, ?) THEN 1 END) as delivered',
            array_map('strval', Order::DROPSHIPPER_COMPLETED_STATUSES)
        )->groupBy('marketer_client_id')->get()->keyBy('marketer_client_id');

        $loginsMap = LoginSuccessLog::whereIn('user_id', $dropshipperIds)
            ->selectRaw('user_id, MAX(COALESCE(logged_in_at, created_at)) as last_login')
            ->groupBy('user_id')
            ->pluck('last_login', 'user_id')
            ->all();

        $tokenLoginsMap = ApiToken::whereIn('user_id', $dropshipperIds)
            ->selectRaw('user_id, MAX(COALESCE(last_used_at, created_at)) as last_token')
            ->groupBy('user_id')
            ->pluck('last_token', 'user_id')
            ->all();

        $sevenDaysAgo = now()->subDays(7);
        $thirtyDaysAgo = now()->subDays(30);

        $totalDelivered = 0;
        $totalClicks = 0;
        $activeCount = 0;
        $moderateCount = 0;
        $inactiveCount = 0;

        $enrichedClients = $allRelationships->map(function ($rel) use (
            $clicksMap, $ordersStats, $loginsMap, $tokenLoginsMap,
            $sevenDaysAgo, $thirtyDaysAgo,
            &$totalDelivered, &$totalClicks, &$activeCount, &$moderateCount, &$inactiveCount
        ) {
            $dropshipperId = $rel->dropshipper_user_id;
            $clicks = (int) ($clicksMap[$dropshipperId] ?? 0);
            $orderStat = $ordersStats->get($rel->id);
            $placed = (int) ($orderStat?->placed ?? 0);
            $delivered = (int) ($orderStat?->delivered ?? 0);

            $lastLoginStr = $loginsMap[$dropshipperId] ?? $tokenLoginsMap[$dropshipperId] ?? null;
            if ($lastLoginStr && isset($loginsMap[$dropshipperId], $tokenLoginsMap[$dropshipperId])) {
                $lastLoginStr = max($loginsMap[$dropshipperId], $tokenLoginsMap[$dropshipperId]);
            }
            $lastLoginAt = $lastLoginStr ? Carbon::parse($lastLoginStr) : null;

            $isRecentlyActive = ($lastLoginAt && $lastLoginAt->gte($sevenDaysAgo));
            $isModeratelyActive = ($lastLoginAt && $lastLoginAt->gte($thirtyDaysAgo) && $lastLoginAt->lt($sevenDaysAgo));

            if ($isRecentlyActive) {
                $activityStatus = 'active';
                $activeCount++;
            } elseif ($isModeratelyActive) {
                $activityStatus = 'moderate';
                $moderateCount++;
            } else {
                $activityStatus = 'inactive';
                $inactiveCount++;
            }

            $totalDelivered += $delivered;
            $totalClicks += $clicks;

            return [
                'id' => $rel->id,
                'dropshipper_user_id' => $rel->dropshipper_user_id,
                'accepted_at' => $rel->accepted_at ? $rel->accepted_at->toIso8601String() : null,
                'dropshipper' => [
                    'id' => $rel->dropshipper?->id,
                    'name' => $rel->dropshipper?->name ?? 'Unknown',
                    'business_name' => $rel->dropshipper?->business_name,
                ],
                'metrics' => [
                    'product_clicks' => $clicks,
                    'placed_orders' => $placed,
                    'delivered_orders' => $delivered,
                    'last_login_at' => $lastLoginAt ? $lastLoginAt->toIso8601String() : null,
                    'activity_status' => $activityStatus,
                ],
            ];
        });

        $topPerformer = $enrichedClients->sortByDesc(fn ($c) => ($c['metrics']['delivered_orders'] * 1000) + $c['metrics']['product_clicks'])->first();
        $topPerformerInsight = null;
        if ($topPerformer && ($topPerformer['metrics']['delivered_orders'] > 0 || $topPerformer['metrics']['product_clicks'] > 0)) {
            $topName = $topPerformer['dropshipper']['business_name'] ?: $topPerformer['dropshipper']['name'];
            $topPerformerInsight = [
                'client_id' => $topPerformer['id'],
                'client_name' => $topName,
                'clicks' => $topPerformer['metrics']['product_clicks'],
                'delivered' => $topPerformer['metrics']['delivered_orders'],
                'message' => "{$topName} has the highest engagement with {$topPerformer['metrics']['product_clicks']} product clicks and {$topPerformer['metrics']['delivered_orders']} delivered orders.",
            ];
        }

        $highInterest = $enrichedClients->filter(fn ($c) => $c['metrics']['product_clicks'] >= 5 && $c['metrics']['delivered_orders'] <= 2)
            ->sortByDesc(fn ($c) => $c['metrics']['product_clicks'])->first();
        $highInterestInsight = null;
        if ($highInterest) {
            $hiName = $highInterest['dropshipper']['business_name'] ?: $highInterest['dropshipper']['name'];
            $highInterestInsight = [
                'client_id' => $highInterest['id'],
                'client_name' => $hiName,
                'clicks' => $highInterest['metrics']['product_clicks'],
                'delivered' => $highInterest['metrics']['delivered_orders'],
                'message' => "{$hiName} received {$highInterest['metrics']['product_clicks']} product clicks with low order conversion. Follow up personally to assist them.",
            ];
        }

        $summary = [
            'total_clients' => $allRelationships->count(),
            'total_delivered_orders' => $totalDelivered,
            'total_product_clicks' => $totalClicks,
            'recently_active_clients' => $activeCount,
            'distribution' => [
                'active' => $activeCount,
                'moderate' => $moderateCount,
                'inactive' => $inactiveCount,
            ],
        ];

        $insights = [
            'top_performer' => $topPerformerInsight,
            'high_interest_low_orders' => $highInterestInsight,
            'inactive_clients' => $inactiveCount,
        ];

        $filtered = $enrichedClients;
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $filtered = $filtered->filter(function ($c) use ($needle) {
                $name = mb_strtolower($c['dropshipper']['name'] ?? '');
                $bName = mb_strtolower($c['dropshipper']['business_name'] ?? '');
                return str_contains($name, $needle) || str_contains($bName, $needle);
            });
        }

        if ($activity !== 'all') {
            $filtered = $filtered->filter(fn ($c) => $c['metrics']['activity_status'] === $activity);
        }

        if ($minOrders !== null) {
            $filtered = $filtered->filter(fn ($c) => $c['metrics']['delivered_orders'] >= $minOrders);
        }

        $filtered = match ($sortBy) {
            'clicks' => $sortDirection === 'asc'
                ? $filtered->sortBy(fn ($c) => $c['metrics']['product_clicks'])
                : $filtered->sortByDesc(fn ($c) => $c['metrics']['product_clicks']),
            'orders' => $sortDirection === 'asc'
                ? $filtered->sortBy(fn ($c) => $c['metrics']['placed_orders'])
                : $filtered->sortByDesc(fn ($c) => $c['metrics']['placed_orders']),
            'delivered' => $sortDirection === 'asc'
                ? $filtered->sortBy(fn ($c) => $c['metrics']['delivered_orders'])
                : $filtered->sortByDesc(fn ($c) => $c['metrics']['delivered_orders']),
            'last_login' => $sortDirection === 'asc'
                ? $filtered->sortBy(fn ($c) => $c['metrics']['last_login_at'] ?? '1970-01-01')
                : $filtered->sortByDesc(fn ($c) => $c['metrics']['last_login_at'] ?? '1970-01-01'),
            default => $sortDirection === 'asc'
                ? $filtered->sortBy(fn ($c) => $c['accepted_at'] ?? '')
                : $filtered->sortByDesc(fn ($c) => $c['accepted_at'] ?? ''),
        };

        $totalFiltered = $filtered->count();
        $currentPage = max(1, (int) $request->input('page', 1));
        $pagedItems = $filtered->slice(($currentPage - 1) * $perPage, $perPage)->values();
        $lastPage = (int) max(1, ceil($totalFiltered / $perPage));

        return $this->success('Active clients fetched', [
            'data' => $pagedItems,
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $totalFiltered,
            'from' => $totalFiltered > 0 ? (($currentPage - 1) * $perPage + 1) : null,
            'to' => $totalFiltered > 0 ? min($totalFiltered, $currentPage * $perPage) : null,
            'summary' => $summary,
            'insights' => $insights,
        ]);
    }

    public function createClient(Request $request): JsonResponse
    {
        $request->merge([
            'name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name'),
            'email' => $this->normalizeEmail($request->input('email')),
            'phone' => is_string($request->input('phone')) ? trim($request->input('phone')) : $request->input('phone'),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'min:4'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:300'],
        ]);

        $marketer = $request->user();
        abort_unless($marketer?->user_type === 'marketer' && $marketer->banned === 0, 403, 'Active marketer account required');

        if (!empty($data['password'])) {
            $rawPassword = $data['password'];
        } else {
            $digits = preg_replace('/\D+/', '', $data['phone'] ?? '');
            $rawPassword = strlen($digits) >= 6 ? $digits : '123456';
        }

        $result = DB::transaction(function () use ($data, $rawPassword, $marketer) {
            $dropshipper = User::create([
                'name' => $data['name'],
                'business_name' => $data['business_name'] ?? null,
                'email' => $data['email'],
                'password' => Hash::make($rawPassword),
                'phone' => $data['phone'],
                'address' => $data['address'] ?? null,
                'user_type' => 'dropshipper',
                'banned' => 0,
                'whatsapp_consent' => true,
                'whatsapp_consent_source' => 'marketer_created',
                'whatsapp_consented_at' => now(),
            ]);

            $client = MarketerClient::create([
                'marketer_user_id' => $marketer->id,
                'dropshipper_user_id' => $dropshipper->id,
                'active_dropshipper_user_id' => $dropshipper->id,
                'consent_version' => MarketerClient::CONSENT_VERSION,
                'accepted_at' => now(),
            ]);

            return [
                'client' => $client,
                'dropshipper' => $dropshipper,
            ];
        });

        return $this->success('Dropshipper created and linked successfully', [
            'id' => $result['client']->id,
            'dropshipper' => [
                'id' => $result['dropshipper']->id,
                'name' => $result['dropshipper']->name,
                'email' => $result['dropshipper']->email,
                'phone' => $result['dropshipper']->phone,
            ],
            'accepted_at' => $result['client']->accepted_at,
        ], 201);
    }

    public function relationship(Request $request): JsonResponse
    {
        $this->requireDropshipper($request);

        return $this->success('Current marketer relationship fetched', MarketerClient::where('active_dropshipper_user_id', $request->user()->id)
            ->select(['id', 'marketer_user_id', 'accepted_at', 'consent_version'])
            ->with('marketer:id,name,business_name')->first());
    }

    public function performance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $query = Order::query()->join('marketer_clients', 'marketer_clients.id', '=', 'orders.marketer_client_id')
            ->where('marketer_clients.marketer_user_id', $request->user()->id);
        if (isset($data['client_id'])) {
            $query->where('marketer_clients.id', $data['client_id']);
        }
        if (isset($data['from'])) {
            $query->whereDate('orders.created_at', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->whereDate('orders.created_at', '<=', $data['to']);
        }

        $stats = $query->selectRaw(
            'COUNT(*) AS placed, COUNT(CASE WHEN orders.status IN (?, ?) THEN 1 END) AS delivered, '
            .'COUNT(CASE WHEN orders.status = ? THEN 1 END) AS cancelled, '
            .'COUNT(CASE WHEN orders.status = ? THEN 1 END) AS returned',
            [...array_map('strval', Order::DROPSHIPPER_COMPLETED_STATUSES), '7', '8'],
        )->toBase()->first();

        return $this->success('Order performance fetched', [
            'placed' => (int) $stats->placed,
            'delivered' => (int) $stats->delivered,
            'cancelled' => (int) $stats->cancelled,
            'returned' => (int) $stats->returned,
            'points' => (int) $stats->delivered,
        ]);
    }

    private function pendingInvitation(string $code, bool $lock = false): MarketerInvitation
    {
        $query = MarketerInvitation::where('token_hash', hash('sha256', $code))
            ->whereNull('revoked_at')->whereNull('marketer_client_id')->where('expires_at', '>', now());
        $invite = $lock ? $query->lockForUpdate()->first() : $query->first();
        abort_unless($invite, 404, 'Invitation unavailable');

        return $invite;
    }

    private function requireDropshipper(Request $request): void
    {
        $user = $request->user();
        abort_unless($user?->user_type === 'dropshipper' && $user->banned === 0, 403, 'Active dropshipper account required');
        abort_unless($request->attributes->get('api_token')?->hasScope('marketer-client-consent'), 403, 'Sign in directly as the dropshipper to manage consent');
    }

    private function normalizeEmail(mixed $email): mixed
    {
        return is_string($email) ? strtolower(trim($email)) : $email;
    }

    private function perPage(Request $request): int
    {
        return (int) ($request->validate(['per_page' => ['nullable', 'integer', 'between:1,100']])['per_page'] ?? 20);
    }

    private function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data], $status);
    }
}
