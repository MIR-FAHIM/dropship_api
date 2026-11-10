<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketerClient extends Model
{
    public const CONSENT_VERSION = 'pilot-v1';

    public $timestamps = false;

    protected $fillable = [
        'marketer_user_id', 'dropshipper_user_id', 'active_dropshipper_user_id',
        'consent_version', 'accepted_at', 'ended_at', 'ended_by',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function marketer()
    {
        return $this->belongsTo(User::class, 'marketer_user_id');
    }

    public function dropshipper()
    {
        return $this->belongsTo(User::class, 'dropshipper_user_id');
    }

    public static function attributionFor(?int $dropshipperId): ?int
    {
        if (!$dropshipperId) {
            return null;
        }

        return self::where('active_dropshipper_user_id', $dropshipperId)
            ->whereHas('marketer', fn ($query) => $query->where('user_type', 'marketer')->where('banned', 0))
            ->value('id');
    }
}
