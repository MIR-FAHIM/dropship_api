<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemChangeLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'order_item_id',
        'admin_id',
        'action',
        'previous_values',
        'new_values',
    ];

    protected $casts = [
        'previous_values' => 'array',
        'new_values' => 'array',
    ];

    public static function snapshot(OrderItem $item): array
    {
        return $item->only([
            'product_id', 'product_name', 'sku', 'shop_id', 'attribute_id',
            'unit_price', 'admin_price', 'reseller_price', 'qty',
            'line_total', 'line_total_reseller_profit', 'status', 'note',
        ]);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
