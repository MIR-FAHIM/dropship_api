<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderSettlement extends Model
{
    use HasFactory;

    public const TYPE_SUPPLIER_PRODUCT_PRICE = 'supplier_product_price';
    public const TYPE_RESELLER_PROFIT = 'reseller_profit';
    public const TYPE_COMPANY_EARNING = 'company_earning';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'order_id',
        'payable_user_id',
        'vendor_id',
        'user_type',
        'settlement_type',
        'settleable_amount',
        'currency',
        'status',
        'admin_note',
        'trx_id',
        'created_by',
        'settled_at',
    ];

    protected $casts = [
        'settleable_amount' => 'float',
        'settled_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function payableUser()
    {
        return $this->belongsTo(User::class, 'payable_user_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
