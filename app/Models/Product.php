<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'category_id',
        'sub_category_id',
        'brand_id',
        'related_id',

        'name',
        'slug',
        'sku',

        'short_description',
        'description',
        'thumbnail',

        'price',
        'sale_price',
        'cost_price',

        'stock',
        'track_stock',
        'is_active',

        'status',
    ];

    protected $casts = [
        'price' => 'float',
        'sale_price' => 'float',
        'cost_price' => 'float',
        'stock' => 'integer',
        'track_stock' => 'boolean',
        'is_active' => 'boolean',
    ];


        public function images()
    {
        return $this->hasMany(ProductImage::class, 'product_id');
    }

    // 2) Primary image (one row where is_primary = true)

    public function primaryImage()
    {
        return $this->hasOne(ProductImage::class, 'product_id')->where('is_primary', true);
    }
    public function shop()
    {
        // Your model class is Shops (plural), table is shops
        return $this->belongsTo(Shops::class, 'shop_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function subCategory()
    {
        // Still points to categories table
        return $this->belongsTo(Category::class, 'sub_category_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function related()
    {
        return $this->belongsTo(Product::class, 'related_id');
    }

    public function productAttributes()
    {
        return $this->hasMany(ProductAttribute::class, 'product_id');
    }

    public function productDiscount()
    {
        return $this->hasOne(ProductDiscount::class, 'product_id');
    }
}
