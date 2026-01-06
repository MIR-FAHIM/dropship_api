<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'shop_id',

        'product_name',
        'sku',

        'unit_price',
        'qty',
        'line_total',

        'status',
    ];

    protected $casts = [
        'unit_price' => 'float',
        'qty' => 'integer',
        'line_total' => 'float',
    ];

    /**
     * Order header this item belongs to
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Original product reference (optional for history)
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Vendor (shop) responsible for fulfillment
     * Your model name is Shops (plural)
     */
    public function shop()
    {
        return $this->belongsTo(Shops::class, 'shop_id');
    }
}
