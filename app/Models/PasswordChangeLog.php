<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PasswordChangeLog extends Model
{
    public const TYPE_SELF_CHANGE = 'self_change';
    public const TYPE_ADMIN_RESET = 'admin_reset';
    public const TYPE_OTP_RESET = 'otp_reset';

    public const TYPES = [self::TYPE_SELF_CHANGE, self::TYPE_ADMIN_RESET, self::TYPE_OTP_RESET];

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'changed_by',
        'change_type',
        'note',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'changed_by' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Record a password change. Never pass the password itself.
     */
    public static function record(User $user, ?User $actor, string $type, ?Request $request = null, ?string $note = null): self
    {
        return static::create([
            'user_id' => $user->id,
            'changed_by' => $actor?->id,
            'change_type' => $type,
            'note' => $note !== null && trim($note) !== '' ? Str::limit(trim($note), 250, '') : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'created_at' => now(),
        ]);
    }
}
