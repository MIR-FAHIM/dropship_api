<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SettlementInvoice extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';
    const STATUS_PAID = 'paid';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

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
        'admin_payment_details',
        'paid_by',
        'paid_amount',
        'paid_at',
        'sender_account',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
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
        'paid_amount' => 'float',
        'order_ids' => 'array',
        'orders_breakdown' => 'array',
        'admin_payment_details' => 'array',
        'settled_at' => 'datetime',
        'paid_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
