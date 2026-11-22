<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Return charges CarryBee took for a returned parcel (charges − cash collected), and who carries them.
 * payer null = not decided yet. See OrderAccountingService::calculateOrderStakeBreakdown().
 */
class OrderReturnPenalty extends Model
{
    public const PAYERS = ['vendor', 'admin', 'dropshipper'];

    protected $fillable = ['order_id', 'amount', 'payer', 'note', 'decided_by', 'decided_at'];

    protected $casts = [
        'amount' => 'float',
        'decided_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
