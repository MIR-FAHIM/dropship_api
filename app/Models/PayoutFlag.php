<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A payout already made (or locked on a pending payout invoice) that doesn't match CarryBee's final figures.
 * Only shown to admins; nothing is recovered automatically.
 */
class PayoutFlag extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'order_id', 'party', 'settlement_id', 'flag', 'recorded', 'correct', 'difference', 'reason',
        'status', 'note', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'recorded' => 'float',
        'correct' => 'float',
        'difference' => 'float',
        'resolved_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
