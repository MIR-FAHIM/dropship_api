<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResellerProductPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'reseller_id',
        'product_id',
        'slug',
        'selling_price',
        'discount_price',
        'custom_title',
        'custom_description',
        'delivery_charge',
        'template_id',
        'published_status',
    ];

    protected $casts = [
        'selling_price' => 'float',
        'discount_price' => 'float',
        'delivery_charge' => 'float',
        'template_id' => 'integer',
    ];

    public function reseller()
    {
        return $this->belongsTo(ResellerStoreProfile::class, 'reseller_id', 'reseller_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
