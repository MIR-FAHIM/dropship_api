<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LandingPageOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'reseller_product_page_id',
        'reseller_id',
        'product_id',
        'order_id',
        'customer_name',
        'customer_phone',
        'customer_address',
        'district_id',
        'division_id',
        'upozella_id',
        'variant_id',
        'quantity',
        'selling_price',
        'delivery_charge',
        'total_amount',
        'status',
        'is_outside_dhaka',
        'source',
        'tracking_code',
        'passed_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'selling_price' => 'float',
        'delivery_charge' => 'float',
        'total_amount' => 'float',
        'is_outside_dhaka' => 'boolean',
        'passed_at' => 'datetime',
    ];

    public function resellerProductPage()
    {
        return $this->belongsTo(ResellerProductPage::class);
    }

    public function reseller()
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function district()
    {
        return $this->belongsTo(District::class);
    }

    public function division()
    {
        return $this->belongsTo(Division::class);
    }

    public function upazila()
    {
        return $this->belongsTo(Upazila::class, 'upozella_id');
    }
}
