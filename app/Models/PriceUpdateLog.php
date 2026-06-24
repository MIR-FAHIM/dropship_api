<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class PriceUpdateLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'before_price',
        'new_price',
        'updated_by',
        'status',
        'note',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'before_price' => 'decimal:2',
        'new_price' => 'decimal:2',
        'updated_by' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
