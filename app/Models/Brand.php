<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'logo',
        'status',
    ];

    /**
     * Products under this brand
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'brand_id');
    }
    public function logo()
    {
        return $this->belongsTo(Upload::class, 'logo');
    }
}
