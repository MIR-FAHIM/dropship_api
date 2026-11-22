<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Partner Portal F4: a marketer's product sourcing / price confirmation request. */
class PartnerProductRequirement extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CHECKING = 'checking';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_CHECKING];

    public const PLATFORMS = ['facebook_instagram', 'tiktok', 'google_youtube', 'other'];
    public const CAMPAIGN_STATUSES = ['active', 'upcoming', 'testing'];
    public const DEADLINES = ['2h', '6h', '24h', 'days', 'date'];

    protected $fillable = [
        'partner_id', 'marketer_client_id', 'product_name', 'product_image', 'campaign_platform',
        'daily_budget_usd', 'campaign_status', 'confirmation_deadline', 'deadline_at', 'special_note',
        'status', 'quoted_price_bdt', 'admin_note', 'linked_product_id', 'handled_by', 'responded_at',
    ];

    protected $casts = [
        'daily_budget_usd' => 'float',
        'quoted_price_bdt' => 'float',
        'deadline_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    protected $appends = ['is_open', 'is_urgent', 'is_overdue'];

    public function partner()
    {
        return $this->belongsTo(User::class, 'partner_id');
    }

    public function client()
    {
        return $this->belongsTo(MarketerClient::class, 'marketer_client_id');
    }

    public function linkedProduct()
    {
        return $this->belongsTo(Product::class, 'linked_product_id');
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function getIsOpenAttribute(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Open and due within 6 hours (or asked for "within 2/6 hours"). */
    public function getIsUrgentAttribute(): bool
    {
        return $this->is_open && (in_array($this->confirmation_deadline, ['2h', '6h'], true)
            || ($this->deadline_at && $this->deadline_at->lte(now()->addHours(6))));
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->is_open && $this->deadline_at && $this->deadline_at->isPast();
    }
}
