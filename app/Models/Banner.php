<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use HasFactory;

    protected $table = 'banners';

    /**
     * Mass assignable attributes
     */
    protected $fillable = [
        'banner_name',
        'title',
        'related_product_id',
        'related_category_id',
        'image_path',
        'image_id',
        'note',
        'is_active',
    ];

    /**
     * Attribute casting
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Relationships
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'related_product_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'related_category_id');
    }
}
