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
    ];

    protected $casts = [
        'collectable_amount' => 'decimal:2',
        'delivery_fee'       => 'decimal:2',
        'total_fee'          => 'decimal:2',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
