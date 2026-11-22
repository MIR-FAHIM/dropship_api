<?php

namespace App\Service;

use App\Models\MarketerClient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Partner Portal F3 — a marketer's public referral code (RB-SCALEUP12).
 * A dropshipper enters it on Marketer Partnership (or opens /app/marketer-partnership?ref=CODE),
 * sees the same consent text as an invitation, and accepts it from their own account.
 * Same rules as invitations: active, non-banned marketer; one active marketer per dropshipper.
 */
class MarketerReferralService
{
    /** The marketer's code, created on first use: RB- + up to 8 letters of the business/name + id. */
    public function codeFor(User $marketer): string
    {
        if ($marketer->marketer_ref_code) {
            return $marketer->marketer_ref_code;
        }
        $letters = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($marketer->business_name ?: $marketer->name)));
        $code = 'RB-' . (substr($letters, 0, 8) ?: 'PARTNER') . $marketer->id; // id suffix keeps it unique
        $marketer->forceFill(['marketer_ref_code' => $code])->save();

        return $code;
    }

    /** Active marketer behind a code (case and spaces ignored), or null. */
    public function marketerFor(string $code): ?User
    {
        $code = strtoupper(preg_replace('/\s+/', '', $code));
        if (!preg_match('/^RB-[A-Z0-9]{2,29}$/', $code)) {
            return null;
        }

        return User::where('user_type', 'marketer')->where('banned', 0)->where('marketer_ref_code', $code)->first();
    }

    /** Connect a dropshipper to the marketer behind $code. Aborts 404 / 409 like the invitation flow. */
    public function accept(User $dropshipper, string $code, string $consentVersion): MarketerClient
    {
        return DB::transaction(function () use ($dropshipper, $code, $consentVersion) {
            $marketer = $this->marketerFor($code);
            abort_unless($marketer, 404, 'Referral code not found');
            $marketer = User::lockForUpdate()->find($marketer->id);
            $dropshipper = User::where('user_type', 'dropshipper')->where('banned', 0)->lockForUpdate()->find($dropshipper->id);
            abort_unless($dropshipper, 404, 'Referral code not found');
            abort_if(MarketerClient::where('active_dropshipper_user_id', $dropshipper->id)->exists(), 409, 'Dropshipper already has an active marketer');

            return MarketerClient::create([
                'marketer_user_id' => $marketer->id,
                'dropshipper_user_id' => $dropshipper->id,
                'active_dropshipper_user_id' => $dropshipper->id,
                'consent_version' => $consentVersion,
                'accepted_at' => now(),
            ]);
        }, 3);
    }
}
