<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use SoftDeletes;

    public const ROLES = ['dropshipper', 'supplier'];

    /** Built-in roles plus the ones admins added (lead_roles). */
    public static function allRoles(): array
    {
        return array_values(array_unique(array_merge(self::ROLES, LeadRole::pluck('value')->all())));
    }
    public const STATUSES = ['new', 'contacted', 'converted', 'cancelled'];
    public const FOLLOWUP_TAGS = ['phone_off', 'no_answer', 'call_later', 'messaged', 'interested', 'not_interested'];
    public const SOURCES = ['manual', 'dropshipper'];

    /** Activity types that count as "contacted today". */
    public const CONTACT_TYPES = ['call', 'whatsapp', 'note', 'followup_changed', 'scheduled'];

    protected $fillable = [
        'name', 'phone', 'phone_normalized', 'role', 'status', 'followup_tag', 'page_link',
        'product_tag_id', 'note', 'next_call_at', 'source', 'user_id', 'assigned_to',
        'created_by', 'last_contacted_at', 'converted_at',
    ];

    protected $casts = [
        'product_tag_id' => 'integer',
        'user_id' => 'integer',
        'assigned_to' => 'integer',
        'created_by' => 'integer',
        'next_call_at' => 'datetime',
        'last_contacted_at' => 'datetime',
        'converted_at' => 'datetime',
    ];

    public function productTag(): BelongsTo
    {
        return $this->belongsTo(LeadProductTag::class, 'product_tag_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Bangladesh mobile numbers in one form: 01XXXXXXXXX. Other numbers are kept
     * as their digits. Returns '' when there are no digits.
     */
    public static function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '880') && strlen($digits) === 13) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '1') && strlen($digits) === 10) {
            $digits = '0' . $digits;
        }

        return $digits;
    }

    /** Ways the same number may be stored on users.phone. */
    public static function phoneVariants(string $normalized): array
    {
        if ($normalized === '') {
            return [];
        }
        $local = ltrim($normalized, '0');

        return array_values(array_unique([
            $normalized, $local, '880' . $local, '+880' . $local, '88' . $normalized, '+88' . $normalized,
        ]));
    }
}
