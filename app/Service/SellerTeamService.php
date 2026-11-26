<?php

namespace App\Service;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Passive Income phase 1 — the seller team (referral) system.
 *
 *  - A dropshipper with 10 fully delivered orders becomes a verified seller and gets a seller code (RS-XXXXXX).
 *  - A new dropshipper can sign up with that code; users.referred_by = the code owner (their upline).
 *  - The upline is set only at sign-up or by an admin (with a reason). Every change goes to seller_team_logs.
 *  - Team = 4 levels below a seller. Commission on the team's sales comes in phase 2.
 */
class SellerTeamService
{
    public const ORDERS_TO_VERIFY = 10;
    public const LEVELS = 4;
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I

    /** Fully delivered orders: same definition as the advance-deposit rule. */
    public function deliveredOrders(int $userId): int
    {
        return Order::where('user_id', $userId)
            ->where(fn ($q) => $q->where('status', 9)->orWhereHas('orderStatus', fn ($s) => $s->whereIn('type', ['delivered', 'completed'])))
            ->count();
    }

    public function isVerified(?User $u): bool
    {
        return $u !== null && $u->seller_verified_at !== null && $u->seller_code !== null;
    }

    /**
     * Gives the seller code when the dropshipper has reached 10 delivered orders. Safe to call any time
     * (after a delivery, when the team page opens, from the backfill command). Returns true if newly verified.
     */
    public function checkVerification(User $user): bool
    {
        if ($user->user_type !== 'dropshipper' || $this->isVerified($user)) {
            return false;
        }
        if ($this->deliveredOrders($user->id) < self::ORDERS_TO_VERIFY) {
            return false;
        }
        $this->verify($user, null, 'Reached ' . self::ORDERS_TO_VERIFY . ' delivered orders');

        return true;
    }

    /** Make a dropshipper a verified seller (system or admin). Keeps an existing code. */
    public function verify(User $user, ?int $actorId, ?string $reason = null): User
    {
        return DB::transaction(function () use ($user, $actorId, $reason) {
            $user = User::lockForUpdate()->findOrFail($user->id);
            if ($this->isVerified($user)) {
                return $user;
            }
            $user->forceFill([
                'seller_code' => $user->seller_code ?: $this->newCode(),
                'seller_verified_at' => now(),
            ])->save();
            $this->log($user->id, 'verified', null, null, $user->seller_code, $actorId, $reason);

            app(NotificationService::class)->createNotificationSafely([
                'title' => 'অভিনন্দন! আপনি এখন ভেরিফাইড সেলার',
                'subtitle' => "আপনার সেলার কোড: {$user->seller_code}। এই কোড দিয়ে নতুন সেলার রেজিস্ট্রেশন করলে তারা আপনার টিমে যুক্ত হবে।",
                'created_by' => null, 'send_to' => $user->id, 'is_seen' => false,
                'type' => 'seller_team', 'is_active' => true, 'image' => null, 'module' => 'seller_team',
            ]);

            return $user;
        });
    }

    /** Admin takes verification away. The code stops working for new sign-ups; the team stays. */
    public function unverify(User $user, int $actorId, string $reason): User
    {
        $user->forceFill(['seller_verified_at' => null])->save();
        $this->log($user->id, 'unverified', null, null, $user->seller_code, $actorId, $reason);

        return $user;
    }

    /** The verified, active seller behind a code (case and spaces ignored), or null. */
    public function sellerForCode(?string $code): ?User
    {
        $code = strtoupper(preg_replace('/\s+/', '', (string) $code));
        if (!preg_match('/^RS-[A-Z0-9]{6}$/', $code)) {
            return null;
        }
        $seller = User::where('seller_code', $code)->where('user_type', 'dropshipper')->where('banned', 0)->first();

        return $this->isVerified($seller) ? $seller : null;
    }

    /** Link a newly registered dropshipper to the code owner. Throws \InvalidArgumentException with a user message. */
    public function joinWithCode(User $newUser, string $code): User
    {
        $upline = $this->sellerForCode($code);
        if (!$upline) {
            throw new \InvalidArgumentException('এই সেলার কোডটি সঠিক নয় বা এখন সক্রিয় নেই।');
        }
        $this->assertCanLink($newUser, $upline);
        $newUser->forceFill(['referred_by' => $upline->id, 'referred_at' => now()])->save();
        $this->log($newUser->id, 'joined', null, $upline->id, $upline->seller_code, null, 'Signed up with seller code');

        return $newUser;
    }

    /** Admin sets (or clears with null) a dropshipper's upline. */
    public function changeUpline(User $user, ?User $newUpline, int $actorId, string $reason): User
    {
        if ($newUpline) {
            $this->assertCanLink($user, $newUpline);
        }
        $old = $user->referred_by;
        $user->forceFill(['referred_by' => $newUpline?->id, 'referred_at' => $newUpline ? now() : null])->save();
        $this->log($user->id, 'upline_changed', $old, $newUpline?->id, $newUpline?->seller_code, $actorId, $reason);

        return $user;
    }

    /** No self-referral (same account or same phone) and no loops (the upline can't be in the user's own team). */
    private function assertCanLink(User $user, User $upline): void
    {
        if ($user->id === $upline->id) {
            throw new \InvalidArgumentException('নিজের কোড দিয়ে নিজের টিমে যোগ দেওয়া যায় না।');
        }
        $digits = fn ($p) => substr(preg_replace('/\D+/', '', (string) $p), -10);
        if ($user->phone && $upline->phone && $digits($user->phone) === $digits($upline->phone)) {
            throw new \InvalidArgumentException('নিজের কোড দিয়ে নিজের টিমে যোগ দেওয়া যায় না।');
        }
        // Walk up from the new upline: if we meet the user, linking would make a loop.
        $seen = [];
        for ($id = $upline->referred_by; $id && !isset($seen[$id]); $id = User::whereKey($id)->value('referred_by')) {
            if ((int) $id === (int) $user->id) {
                throw new \InvalidArgumentException('এই সেলার এই ইউজারের নিজের টিমের সদস্য — লিংক করা যাবে না।');
            }
            $seen[$id] = true;
        }
    }

    /** Upline chain: level 1 (direct) up to level 4. */
    public function uplines(User $user): Collection
    {
        $chain = collect();
        $id = $user->referred_by;
        for ($level = 1; $level <= self::LEVELS && $id; $level++) {
            $u = User::find($id);
            if (!$u || $chain->contains(fn ($c) => $c['user']->id === $u->id)) {
                break;
            }
            $chain->push(['level' => $level, 'user' => $u]);
            $id = $u->referred_by;
        }

        return $chain;
    }

    /** Member ids per level 1..4 below a seller. */
    public function teamIds(int $userId): array
    {
        $levels = [];
        $parents = [$userId];
        $seen = [$userId => true];
        for ($level = 1; $level <= self::LEVELS; $level++) {
            $ids = $parents ? User::whereIn('referred_by', $parents)->pluck('id')->reject(fn ($id) => isset($seen[$id]))->values()->all() : [];
            foreach ($ids as $id) {
                $seen[$id] = true;
            }
            $levels[$level] = $ids;
            $parents = $ids;
        }

        return $levels;
    }

    private function newCode(): string
    {
        do {
            $code = 'RS-';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (User::where('seller_code', $code)->exists());

        return $code;
    }

    private function log(int $userId, string $action, ?int $old, ?int $new, ?string $code, ?int $actorId, ?string $reason): void
    {
        DB::table('seller_team_logs')->insert([
            'user_id' => $userId, 'action' => $action, 'old_upline_id' => $old, 'new_upline_id' => $new,
            'seller_code' => $code, 'actor_id' => $actorId, 'reason' => $reason ? mb_substr($reason, 0, 500) : null, 'created_at' => now(),
        ]);
    }
}
