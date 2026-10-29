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

    /**
     * Backward-compatible accessor: ensures orders_breakdown entries have 'items' with product details and thumbnail.
     */
    public function getOrdersBreakdownAttribute($value)
    {
        $breakdown = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($breakdown)) {
            return [];
        }

        $needsItems = false;
        $orderIds = [];
        foreach ($breakdown as $b) {
            if (empty($b['items']) && !empty($b['order_id'])) {
                $needsItems = true;
                $orderIds[] = (int) $b['order_id'];
            }
        }

        if ($needsItems && !empty($orderIds)) {
            $orders = Order::with(['items.product.primaryImage'])->whereIn('id', $orderIds)->get()->keyBy('id');
            foreach ($breakdown as &$b) {
                if (empty($b['items']) && !empty($b['order_id']) && isset($orders[$b['order_id']])) {
                    $order = $orders[$b['order_id']];
                    $itemsData = [];
                    foreach ($order->items as $item) {
                        $product = $item->product;
                        $itemsData[] = [
                            'id' => $item->id,
                            'product_id' => $item->product_id,
                            'product_name' => $item->product_name ?: ($product?->name ?? ('Product #' . $item->product_id)),
                            'qty' => (int) ($item->qty ?? $item->quantity ?? 1),
                            'unit_price' => round((float) ($item->unit_price ?? $product?->unit_price ?? 0), 2),
                            'sku' => $item->sku ?: $product?->sku,
                            'product_thumbnail' => $product?->primaryImage?->file_name ?: ($product?->primaryImage?->url ?: $product?->thumbnail_img),
                        ];
                    }
                    $b['items'] = $itemsData;
                }
            }
            unset($b);
        }

        return $breakdown;
    }
}
