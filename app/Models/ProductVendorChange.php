<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One "Change vendor" on a product: who moved it, from/to which vendor, and what else changed. */
class ProductVendorChange extends Model
{
    protected $fillable = [
        'product_id',
        'from_vendor_id',
        'to_vendor_id',
        'old_sku',
        'new_sku',
        'cart_items_moved',
        'open_orders_left',
        'reason',
        'changed_by',
    ];

    protected $casts = [
        'cart_items_moved' => 'integer',
        'open_orders_left' => 'integer',
    ];

    public function fromVendor()
    {
        return $this->belongsTo(Vendor::class, 'from_vendor_id');
    }

    public function toVendor()
    {
        return $this->belongsTo(Vendor::class, 'to_vendor_id');
    }

    public function changer()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
