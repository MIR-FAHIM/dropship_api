<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductClicks extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'page',
        'clicked_by',
        'is_guest',
        'ip_address',
    ];

    protected $casts = [
        'is_guest' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'clicked_by');
    }
}
