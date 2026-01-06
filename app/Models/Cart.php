<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'total_items',
        'subtotal',
    ];

    protected $casts = [
        'total_items' => 'integer',
        'subtotal' => 'float',
    ];

    /**
     * Cart belongs to a user
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Cart has many cart items
     */
    public function items()
    {
        return $this->hasMany(CartItem::class, 'cart_id');
    }
}
