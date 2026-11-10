<?php

namespace App\Http\Controllers;

use App\Models\MarketerClient;
use App\Models\MarketerInvitation;
use App\Models\Order;
use App\Models\User;
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
        $perPage = $this->perPage($request);

        return $this->success('Active clients fetched', MarketerClient::where('marketer_user_id', $request->user()->id)
            ->whereNotNull('active_dropshipper_user_id')->select(['id', 'dropshipper_user_id', 'accepted_at'])
            ->with('dropshipper:id,name')->latest('id')->paginate($perPage));
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
