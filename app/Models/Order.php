<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_number',

        'status',
        'payment_status',

        'customer_name',
        'customer_phone',
        'shipping_address',

        'zone',
        'district',
        'area',
        'lat',
        'lon',

        'subtotal',
        'reseller_price',
        'shipping_fee',
        'discount',
        'total',
        'reseller_profit',
        'vendor_id',

        'advance_amount',
        'advance_status',
        'advance_recipient',
        'advance_payment_method',
        'advance_trx_id',
        'advance_sender_phone',
        'advance_verified_at',
        'advance_verified_by',
        'advance_admin_note',
        'remaining_cod',
        'courier_account_owner',
        'actual_courier_fee',
        'actual_cod_fee',
        'actual_courier_cost',
        'courier_cost_confirmed',
        'courier_net_payout',
        'courier_payout_status',

        'note',
    ];

    protected $casts = [
        'subtotal' => 'float',
        'shipping_fee' => 'float',
        'discount' => 'float',
        'total' => 'float',
        'reseller_profit' => 'float',
        'advance_amount' => 'float',
        'advance_verified_at' => 'datetime',
        'remaining_cod' => 'float',
        'actual_courier_fee' => 'float',
        'actual_cod_fee' => 'float',
        'actual_courier_cost' => 'float',
        'courier_cost_confirmed' => 'boolean',
        'courier_net_payout' => 'float',
        'lat' => 'float',
        'lon' => 'float',
    ];

    protected $appends = [
        'has_consignment',
        'is_editable',
        'consignment_id',
    ];

    /**
     * Order belongs to a customer
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'id');
    }
    
    public function status()
    {
        return $this->belongsTo(OrderStatus::class, 'status', 'id');
    }
    
    public function deliveryMan()
    {
        return $this->hasOne(AssignDeliveryMan::class, 'order_id')
            ->where('status', 'assigned');
    }
    public function deliveryInformation()
    {
        return $this->hasOne(DeliveryAssignedInfo::class, 'order_id');
    
    }

    /**
     * Order has many order items (the truth)
     */
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }
    public function statusHistory()
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id')
        ->orderBy('created_at', 'desc');
    }
    public function carryBeeDraft()
    {
        return $this->hasOne(CarryBeeOrderCreateForm::class, 'order_id');
        
    }

    public function settlements()
    {
        return $this->hasMany(OrderSettlement::class, 'order_id');
    }

    public function accountingLedgers()
    {
        return $this->hasMany(OrderAccountingLedger::class, 'order_id');
    }

    /**
     * Determines whether an actual delivery consignment has been created or linked to this order.
     */
    public function hasConsignment(): bool
    {
        if (!empty($this->attributes['consignment_id'])) {
            return true;
        }

        if ($this->relationLoaded('deliveryInformation') && !empty($this->deliveryInformation?->consignment_id)) {
            return true;
        }

        $assignedExists = DeliveryAssignedInfo::where('order_id', $this->id)
            ->whereNotNull('consignment_id')
            ->where('consignment_id', '!=', '')
            ->exists();

        if ($assignedExists) {
            return true;
        }

        if (class_exists(CarrybeeWebhookEvent::class)) {
            $webhookExists = CarrybeeWebhookEvent::where('order_id', $this->id)
                ->whereNotNull('consignment_id')
                ->where('consignment_id', '!=', '')
                ->exists();

            if ($webhookExists) {
                return true;
            }
        }

        return false;
    }

    /**
     * An order is editable only if no consignment has actually been created or linked to it.
     */
    public function isEditable(): bool
    {
        return !$this->hasConsignment();
    }

    /**
     * Resolves the linked consignment ID if present.
     */
    public function getConsignmentId(): ?string
    {
        if (!empty($this->attributes['consignment_id'])) {
            return (string) $this->attributes['consignment_id'];
        }

        if ($this->relationLoaded('deliveryInformation') && !empty($this->deliveryInformation?->consignment_id)) {
            return (string) $this->deliveryInformation->consignment_id;
        }

        $consignmentId = DeliveryAssignedInfo::where('order_id', $this->id)
            ->whereNotNull('consignment_id')
            ->where('consignment_id', '!=', '')
            ->value('consignment_id');

        if ($consignmentId) {
            return (string) $consignmentId;
        }

        if (class_exists(CarrybeeWebhookEvent::class)) {
            $eventConsignment = CarrybeeWebhookEvent::where('order_id', $this->id)
                ->whereNotNull('consignment_id')
                ->where('consignment_id', '!=', '')
                ->value('consignment_id');

            if ($eventConsignment) {
                return (string) $eventConsignment;
            }
        }

        return null;
    }

    public function getHasConsignmentAttribute(): bool
    {
        return $this->hasConsignment();
    }

    public function getIsEditableAttribute(): bool
    {
        return $this->isEditable();
    }

    public function getConsignmentIdAttribute(): ?string
    {
        return $this->getConsignmentId();
    }
}
