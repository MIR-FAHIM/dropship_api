<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Partner Portal F6: marketing content that makes a product a "winning product" for marketers. */
class ProductWinningProfile extends Model
{
    protected $fillable = [
        'product_id', 'is_winning', 'hook', 'ad_angle', 'creative_tip',
        'local_image', 'local_caption', 'original_image', 'original_caption', 'sort_order', 'updated_by',
    ];

    protected $casts = ['is_winning' => 'boolean', 'sort_order' => 'integer'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
