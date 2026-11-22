<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One commission line for a marketer: rate% of the platform profit (markup + delivery
 * margin) on a client's delivered order, or a negative "clawback" line when an already
 * invoiced/paid commission is reversed. See MarketerCommissionService.
 */
class MarketerCommission extends Model
{
    public const TYPE_COMMISSION = 'commission';
    public const TYPE_CLAWBACK = 'clawback';

    public const STATUS_EARNED = 'earned';     // payable (unless it is on an unpaid invoice)
    public const STATUS_PAID = 'paid';
    public const STATUS_REVERSED = 'reversed'; // order cancelled/returned before it was invoiced

    protected $fillable = [
        'marketer_user_id',
        'marketer_client_id',
        'order_id',
        'type',
        'product_markup',
        'delivery_margin',
        'base_amount',
        'rate',
        'amount',
        'status',
        'payout_invoice_id',
        'earned_at',
        'paid_at',
        'reversed_at',
        'note',
    ];

    protected $casts = [
        'product_markup' => 'float',
        'delivery_margin' => 'float',
        'base_amount' => 'float',
        'rate' => 'float',
        'amount' => 'float',
        'earned_at' => 'datetime',
        'paid_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function marketer()
    {
        return $this->belongsTo(User::class, 'marketer_user_id');
    }

    public function client()
    {
        return $this->belongsTo(MarketerClient::class, 'marketer_client_id');
    }

    public function payoutInvoice()
    {
        return $this->belongsTo(SettlementInvoice::class, 'payout_invoice_id');
    }

    /** Earned and not yet put on a payout invoice. */
    public function scopePayable($query)
    {
        return $query->where('status', self::STATUS_EARNED)->whereNull('payout_invoice_id');
    }
}
