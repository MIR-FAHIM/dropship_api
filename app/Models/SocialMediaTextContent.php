<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SocialMediaTextContent extends Model
{
    use HasFactory;

    protected $table = 'social_media_text_contents';

    protected $fillable = [
        'product_id',
        'title',
        'platform',
        'content',
        'css_styles',
        'is_active',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
