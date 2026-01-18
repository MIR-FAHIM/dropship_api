<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    // ✅ Mass assignable fields
    protected $fillable = [
        'name',
        'added_by',
        'user_id',
        'category_id',
        'brand_id',
        'photos',
        'thumbnail_img',
        'video_provider',
        'video_link',
        'tags',
        'description',
        'unit_price',
        'purchase_price',
        'variant_product',
        'attributes',
        'choice_options',
        'colors',
        'variations',
        'todays_deal',
        'published',
        'approved',
        'stock_visibility_state',
        'cash_on_delivery',
        'featured',
        'seller_featured',
        'current_stock',
        'unit',
        'weight',
        'min_qty',
        'low_stock_quantity',
        'discount',
        'discount_type',
        'discount_start_date',
        'discount_end_date',
        'starting_bid',
        'auction_start_date',
        'auction_end_date',
        'tax',
        'tax_type',
        'shipping_type',
        'shipping_cost',
        'is_quantity_multiplied',
        'est_shipping_days',
        'num_of_sale',
        'meta_title',
        'meta_description',
        'meta_img',
        'pdf',
        'slug',
        'refundable',
        'earn_point',
        'rating',
        'barcode',
        'digital',
        'auction_product',
        'file_name',
        'file_path',
        'external_link',
        'external_link_btn',
        'wholesale_product',
        'frequently_brought_selection_type',
    ];

    // ✅ Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
    public function primaryImage()
    {
        return $this->belongsTo(Upload::class, 'thumbnail_img');
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class, 'product_id');
    }

    public function subCategory()
    {
        return $this->belongsTo(Category::class, 'sub_category_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }
public function productDiscount()
{
    return $this->hasOne(ProductDiscount::class, 'product_id');
}

    // ✅ Accessor example for full photo URL
    public function getThumbnailUrlAttribute()
    {
        return $this->thumbnail_img ? asset('storage/' . $this->thumbnail_img) : null;
    }

    public function getPhotosArrayAttribute()
    {
        return $this->photos ? explode(',', $this->photos) : [];
    }
}
