<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CarryBeeOrderCreateForm extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'store_id',
        'merchant_order_id',
        'delivery_type',
        'product_type',
        'recipient_phone',
        'recipient_secendary_phone',
        'recipient_name',
        'recipient_address',
        'city_id',
        'zone_id',
        'area_id',
        'special_instruction',
        'product_description',
        'item_weight',
        'item_quantity',
        'collectable_amount',
        'is_closed_box',
        'is_exchange',
        'own_vendor_id',
        'own_created_by',
        'own_admin_status',
        'own_is_vendor_ready',
        'own_note',
    ];

    protected $casts = [
        'is_closed_box'       => 'boolean',
        'is_exchange'         => 'boolean',
        'own_is_vendor_ready' => 'boolean',
        'item_weight'         => 'float',
        'collectable_amount'  => 'float',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function vendor()
    {
        return $this->belongsTo(User::class, 'own_vendor_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'own_created_by');
    }
}
