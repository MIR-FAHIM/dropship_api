<?php

namespace App\Http\Controllers;

use App\Models\MarketerProfile as Profile;
use App\Models\User;
use App\Service\MarketerCommissionService;
use App\Service\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Partner Portal F8 — partner sign-up, approval, level and profile.
 * Apply (public) → account is created blocked with status "pending" → an admin approves
 * (account opens) or rejects. Level: Pro if ad spend ≥ $10k or campaigns ≥ 100, else Mid;
 * the admin can override it. The level sets the commission rate (Pro 20%, Mid 15%) unless
 * the admin gave the marketer a custom rate.
 */
class MarketerApplicationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly MarketerCommissionService $commissions,
    ) {
    }

    /* ======================= Public ======================= */

    /** POST /partner/apply (multipart, no login) */
    public function apply(Request $request): JsonResponse
    {
        $request->merge(['email' => is_string($request->input('email')) ? strtolower(trim($request->input('email'))) : $request->input('email')]);
        try {
            $data = $request->validate($this->detailRules(creating: true));
        } catch (ValidationException $e) {
            return $this->fail(collect($e->errors())->flatten()->first() ?: 'Validation failed', $e->errors(), 422);
        }
        if ($error = $this->whatsappError($data['whatsapp'])) {
            return $this->fail($error, ['whatsapp' => [$error]], 422);
        }

        $user = DB::transaction(function () use ($request, $data) {
            $user = User::create([
                'name' => trim($data['name']),
                'business_name' => $data['business_name'] ?? null,
                'email' => $data['email'],
                'phone' => $this->normalizeMobile($data['whatsapp']),
                'password' => Hash::make($data['password']),
                'user_type' => 'marketer',
                'role' => 'marketer',
                'banned' => 1, // can't sign in until an admin approves
            ]);
            $profile = new Profile(['user_id' => $user->id, 'application_status' => Profile::STATUS_PENDING, 'level_source' => 'auto']);
            $this->fillDetails($profile, $request, $data, $user->id);
            $profile->save();

            return $user;
        });

        $level = $user->marketerProfile->level === Profile::LEVEL_PRO ? 'Upper Level (Pro)' : 'Mid Level';
        $this->notifications->createAdminNotifications([
            'title' => 'New partner application',
            'subtitle' => ($user->business_name ?: $user->name) . " applied as a marketing partner ({$level}). Review it in Marketers.",
            'created_by' => $user->id, 'is_seen' => false, 'type' => 'partner_application', 'is_active' => true, 'image' => null, 'module' => 'marketer',
        ]);

        return $this->ok('Application received. We will email you when it is approved.', [
            'name' => $user->name, 'email' => $user->email,
            'level' => $user->marketerProfile->level, 'application_status' => Profile::STATUS_PENDING,
        ], 201);
    }

    /* ======================= Admin ======================= */

    /** POST /admin/marketers/{id}/approve */
    public function approve(Request $request, int $id): JsonResponse
    {
        $user = User::where('user_type', 'marketer')->findOrFail($id);
        $profile = Profile::firstOrNew(['user_id' => $user->id]);
        if ($profile->exists && $profile->application_status === Profile::STATUS_APPROVED && !$user->banned) {
            return $this->fail('This partner is already approved.', null, 422);
        }
        $profile->fill(['application_status' => Profile::STATUS_APPROVED, 'rejection_reason' => null,
            'reviewed_by' => $this->adminId($request), 'reviewed_at' => now()])->save();
        $user->update(['banned' => 0]);

        $this->email($user, 'Your Reseller Brain partner account is approved',
            "Hi {$user->name},\n\nYour partner application is approved. Sign in at " . rtrim((string) config('app.frontend_url', 'https://resellerbrain.com'), '/') . "/login with the email and password you registered with.\n\nWelcome to Reseller Brain!");

        return $this->ok('Partner approved', $this->present($user->fresh('marketerProfile')));
    }

    /** POST /admin/marketers/{id}/reject {reason} */
    public function reject(Request $request, int $id): JsonResponse
    {
        try {
            $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        } catch (ValidationException $e) {
            return $this->fail('Tell the applicant why it was not approved.', $e->errors(), 422);
        }
        $user = User::where('user_type', 'marketer')->findOrFail($id);
        $profile = Profile::where('user_id', $user->id)->first();
        if (!$profile || $profile->application_status !== Profile::STATUS_PENDING) {
            return $this->fail('Only a pending application can be rejected. Use Suspend for an active partner.', null, 422);
        }
        $profile->update(['application_status' => Profile::STATUS_REJECTED, 'rejection_reason' => trim($data['reason']),
            'reviewed_by' => $this->adminId($request), 'reviewed_at' => now()]);
        $user->update(['banned' => 1]);

        $this->email($user, 'About your Reseller Brain partner application',
            "Hi {$user->name},\n\nThank you for applying. We can't approve your partner application right now.\nReason: {$profile->rejection_reason}\n\nYou can reply to this email if you have questions.");

        return $this->ok('Application rejected', $this->present($user->fresh('marketerProfile')));
    }

    /** PATCH /admin/marketers/{id}/level {level: pro|mid|auto} */
    public function setLevel(Request $request, int $id): JsonResponse
    {
        try {
            $data = $request->validate(['level' => ['required', Rule::in(['pro', 'mid', 'auto'])]]);
        } catch (ValidationException $e) {
            return $this->fail('Choose Pro, Mid or Automatic.', $e->errors(), 422);
        }
        $user = User::where('user_type', 'marketer')->findOrFail($id);
        $profile = Profile::firstOrNew(['user_id' => $user->id]);
        if (!$profile->exists) {
            $profile->application_status = Profile::STATUS_APPROVED;
        }
        if ($data['level'] === 'auto') {
            $profile->level_source = 'auto';
            $profile->applyAutoLevel();
        } else {
            $profile->level_source = 'admin';
            $profile->level = $data['level'];
        }
        $profile->save();

        return $this->ok('Level saved. It sets the rate for orders delivered from now on.', $this->present($user->fresh('marketerProfile')));
    }

    /* ======================= Marketer ======================= */

    /** GET /marketer/profile */
    public function show(Request $request): JsonResponse
    {
        return $this->ok('Partner profile', $this->present($request->user()->load('marketerProfile')));
    }

    /** POST /marketer/profile (multipart) */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        try {
            $data = $request->validate($this->detailRules(creating: false) + [
                'remove_avatar' => ['nullable', 'boolean'],
                'remove_agency_logo' => ['nullable', 'boolean'],
            ]);
        } catch (ValidationException $e) {
            return $this->fail(collect($e->errors())->flatten()->first() ?: 'Validation failed', $e->errors(), 422);
        }
        if ($error = $this->whatsappError($data['whatsapp'])) {
            return $this->fail($error, ['whatsapp' => [$error]], 422);
        }

        $profile = Profile::firstOrNew(['user_id' => $user->id]);
        if (!$profile->exists) {
            $profile->application_status = Profile::STATUS_APPROVED; // admin-created marketer filling in details
        }
        foreach (['avatar', 'agency_logo'] as $col) {
            if ($request->boolean("remove_{$col}") && $profile->{$col}) {
                Storage::disk('public')->delete($profile->{$col});
                $profile->{$col} = null;
            }
        }
        $this->fillDetails($profile, $request, $data, $user->id);
        $profile->save();
        $user->update(['name' => trim($data['name']), 'business_name' => $data['business_name'] ?? null]);

        return $this->ok('Profile saved', $this->present($user->fresh('marketerProfile')));
    }

    /* ======================= helpers ======================= */

    private function detailRules(bool $creating): array
    {
        $image = ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

        return array_filter([
            'name' => ['required', 'string', 'max:191'],
            'business_name' => ['nullable', 'string', 'max:191'],
            'email' => $creating ? ['required', 'email', 'max:191', 'unique:users,email'] : null,
            'password' => $creating ? ['required', 'string', 'min:6', 'max:100'] : null,
            'whatsapp' => ['required', 'string', 'max:32'],
            'facebook_page' => ['required', 'url', 'max:500'],
            'portfolio_url' => ['nullable', 'url', 'max:500'],
            'ad_spend_usd' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'campaigns_run' => ['required', 'integer', 'min:0', 'max:1000000'],
            'niche' => ['required', Rule::in(Profile::NICHES)],
            'campaign_type' => ['required', Rule::in(Profile::CAMPAIGN_TYPES)],
            'avatar' => $image,
            'agency_logo' => $image,
            'campaign_screenshot' => $image,
        ]);
    }

    private function fillDetails(Profile $profile, Request $request, array $data, int $userId): void
    {
        foreach (['avatar', 'agency_logo', 'campaign_screenshot'] as $col) {
            if ($request->hasFile($col)) {
                if ($profile->{$col}) {
                    Storage::disk('public')->delete($profile->{$col});
                }
                $profile->{$col} = $request->file($col)->store("partner-profiles/{$userId}", 'public');
            }
        }
        $profile->fill([
            'whatsapp' => $this->normalizeMobile($data['whatsapp']),
            'facebook_page' => $data['facebook_page'],
            'portfolio_url' => $data['portfolio_url'] ?? null,
            'ad_spend_usd' => round((float) $data['ad_spend_usd'], 2),
            'campaigns_run' => (int) $data['campaigns_run'],
            'niche' => $data['niche'],
            'campaign_type' => $data['campaign_type'],
        ]);
        $profile->applyAutoLevel();
    }

    /** What the panels need: account, profile, level label and the rate it gives. */
    private function present(User $user): array
    {
        $p = $user->marketerProfile;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'business_name' => $user->business_name,
            'email' => $user->email,
            'suspended' => (bool) $user->banned && (!$p || $p->application_status === Profile::STATUS_APPROVED),
            'application_status' => $p?->application_status ?? Profile::STATUS_APPROVED,
            'rejection_reason' => $p?->rejection_reason,
            'level' => $p?->level,
            'level_label' => match ($p?->level) { 'pro' => 'Upper Level (Pro)', 'mid' => 'Mid Level', default => null },
            'level_source' => $p?->level_source ?? 'auto',
            'commission_rate' => $this->commissions->rateFor($user),
            'custom_rate' => $user->marketer_commission_rate !== null,
            'profile' => $p ? $p->only(['avatar', 'agency_logo', 'whatsapp', 'facebook_page', 'portfolio_url', 'ad_spend_usd',
                'campaigns_run', 'niche', 'campaign_type', 'campaign_screenshot', 'reviewed_at']) : null,
        ];
    }

    private function normalizeMobile(string $value): string
    {
        $digits = preg_replace('/\D+/', '', strtr($value, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']));

        return preg_replace('/^880/', '0', $digits);
    }

    private function whatsappError(string $value): ?string
    {
        return preg_match('/^01[3-9]\d{8}$/', $this->normalizeMobile($value)) ? null : 'Enter a valid 11-digit WhatsApp number, e.g. 017XXXXXXXX.';
    }

    private function email(User $user, string $subject, string $body): void
    {
        try {
            Mail::raw($body, fn ($m) => $m->to($user->email)->subject($subject));
        } catch (\Throwable) {
            // Email failure must not undo the decision; the panel shows the status too.
        }
    }

    private function adminId(Request $request): ?int
    {
        return ($request->attributes->get('api_user') ?? $request->user())?->id;
    }

    private function ok(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data], $status);
    }

    private function fail(string $message, mixed $errors = null, int $status = 400): JsonResponse
    {
        return response()->json(['status' => 'failed', 'message' => $message, 'errors' => $errors], $status);
    }
}
