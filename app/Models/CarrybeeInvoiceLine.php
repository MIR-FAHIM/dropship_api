<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One parcel line of a CarryBee invoice (see CarrybeeReconcileService). */
class CarrybeeInvoiceLine extends Model
{
    public const TYPE_DELIVERY = 'delivery';
    public const TYPE_RETURN = 'return';

    protected $fillable = [
        'account_key', 'vendor_id', 'invoice_id', 'invoiced_date', 'receivable_status', 'consignment_id',
        'merchant_order_id', 'order_id', 'line_type', 'collected', 'delivery_fee', 'cod_fee', 'total_fee', 'delivery_status',
    ];

    protected $casts = [
        'invoiced_date' => 'date:Y-m-d',
        'collected' => 'float',
        'delivery_fee' => 'float',
        'cod_fee' => 'float',
        'total_fee' => 'float',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function isReturnLine(): bool
    {
        return $this->line_type === self::TYPE_RETURN;
    }
}
