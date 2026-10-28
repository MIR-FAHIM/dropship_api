<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SettlementInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_number',
        'vendor_id',
        'user_id',
        'type',
        'orders_count',
        'total_collectable',
        'total_delivery_fee',
        'total_cod_fee',
        'total_courier_net',
        'total_supplier_price',
        'total_amount_owed',
        'total_dropshipper_payout',
        'total_platform_net_profit',
        'payment_method',
        'trx_id',
        'status',
        'order_ids',
        'orders_breakdown',
        'note',
        'settled_at',
    ];

    protected $casts = [
        'orders_count' => 'integer',
        'total_collectable' => 'float',
        'total_delivery_fee' => 'float',
        'total_cod_fee' => 'float',
        'total_courier_net' => 'float',
        'total_supplier_price' => 'float',
        'total_amount_owed' => 'float',
        'total_dropshipper_payout' => 'float',
        'total_platform_net_profit' => 'float',
        'order_ids' => 'array',
        'orders_breakdown' => 'array',
        'settled_at' => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
