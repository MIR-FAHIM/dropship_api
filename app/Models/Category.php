<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'parent_id',
        'level',
        'name',
        'order_level',
        'commision_rate',
        'banner',
        'icon',
        'cover_image',
        'featured',
        'top',
        'digital',
        'slug',
        'meta_title',
        'meta_description',
    ];

    /**
     * Attribute casting.
     */
    protected $casts = [
        'parent_id' => 'integer',
        'level' => 'integer',
        'order_level' => 'integer',
        'commision_rate' => 'float',
        'featured' => 'integer',
        'top' => 'integer',
        'digital' => 'integer',
    ];

    /**
     * Parent category (self-referencing).
     */
    public function parent()
    {
        return $this->belongsTo(Upload::class, 'parent_id');
    }

    public function banner()
    {
        return $this->belongsTo(Upload::class, 'banner');
    }

    /**
     * Child categories (self-referencing).
     */
    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }
}
