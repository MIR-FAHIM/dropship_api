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
        'attribute_id',

        'unit_price',
        'admin_price',
        'reseller_price',
        'qty',
        'line_total',
        'line_total_reseller_profit',

        'status',
        'note',
    ];

    protected $casts = [
        'unit_price' => 'float',
        'admin_price' => 'float',
        'reseller_price' => 'float',
        'qty' => 'integer',
        'line_total' => 'float',
        'line_total_reseller_profit' => 'float',
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
        return $this->belongsTo(Vendor::class, 'shop_id');
    }


       public function productAttribute()
    {
        return $this->belongsTo(ProductAttribute::class, 'attribute_id');
    }
}
