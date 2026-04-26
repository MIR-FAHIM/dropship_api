<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResellerTransaction extends Model
{
    use HasFactory;

     

    protected $fillable = [
        'amount',
        'ref_id',
        'trx_id',
        'trx_type',
        'note',
        'status',
        'source',
        'order_id',
        'type',
    ];

    protected $casts = [
        'amount' => 'float',
        'order_id' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
