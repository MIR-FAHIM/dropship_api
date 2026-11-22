<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Partner Portal F8: a marketer's application/profile and level.
 * Level rule (demo): "pro" (Upper Level) if ad spend ≥ $10,000 or campaigns ≥ 100, else "mid".
 */
class MarketerProfile extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const LEVEL_PRO = 'pro';
    public const LEVEL_MID = 'mid';

    public const NICHES = ['gadgets_electronics', 'fashion_beauty', 'home_kitchen', 'health_lifestyle', 'kids_toys', 'general'];
    public const CAMPAIGN_TYPES = ['purchase_website', 'messenger', 'tiktok_shop', 'mixed'];

    protected $fillable = [
        'user_id', 'avatar', 'agency_logo', 'whatsapp', 'facebook_page', 'portfolio_url',
        'ad_spend_usd', 'campaigns_run', 'niche', 'campaign_type', 'campaign_screenshot',
        'level', 'level_source', 'application_status', 'rejection_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'ad_spend_usd' => 'float',
        'campaigns_run' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function levelFor(float $adSpendUsd, int $campaigns): string
    {
        return $adSpendUsd >= 10000 || $campaigns >= 100 ? self::LEVEL_PRO : self::LEVEL_MID;
    }

    /** Recalculate the level from the numbers, unless an admin set it by hand. */
    public function applyAutoLevel(): void
    {
        if ($this->level_source !== 'admin') {
            $this->level = self::levelFor((float) $this->ad_spend_usd, (int) $this->campaigns_run);
        }
    }
}
