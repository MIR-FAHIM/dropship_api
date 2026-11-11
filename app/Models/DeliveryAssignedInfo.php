<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryAssignedInfo extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'consignment_id',
        'delivery_company_id',
        'merchant_order_id',
        'recipient_name',
        'recipient_phone',
        'recipient_address',
        'collectable_amount',
        'delivery_fee',
        'total_fee',
        'transfer_status_id',
        'carrybee_invoice_id',
        'carrybee_paid_at',
        'courier_stage',
        'courier_stage_at',
        'attempt',
        'rider_name',
        'rider_phone',
        'issue_flag',
        'issue_reason',
        'collected_amount',
        'cod_fee',
        'needs_review',
        'review_reason',
        'courier_checked_at',
    ];

    protected $casts = [
        'collectable_amount' => 'decimal:2',
        'delivery_fee'       => 'decimal:2',
        'total_fee'          => 'decimal:2',
        'carrybee_paid_at'   => 'datetime',
        'courier_stage_at'   => 'datetime',
        'attempt'            => 'integer',
        'collected_amount'   => 'decimal:2',
        'cod_fee'            => 'decimal:2',
        'needs_review'       => 'boolean',
        'courier_checked_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
