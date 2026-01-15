<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductDiscount extends Model
{
    use HasFactory;

    protected $table = 'product_discounts';

    protected $fillable = [
        'product_id',
        'type',
        'value',
        'start_at',
        'end_at',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'value'     => 'decimal:2',
        'start_at'  => 'datetime',
        'end_at'    => 'datetime',
    ];

    /* ================= Relationships ================= */

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /* ================= Business Helpers ================= */

    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $now = now();

        if ($this->start_at && $now->lt($this->start_at)) {
            return false;
        }

        if ($this->end_at && $now->gt($this->end_at)) {
            return false;
        }

        return true;
    }

    public function applyDiscount(float $price): float
    {
        if (!$this->isValid()) {
            return $price;
        }

        if ($this->type === 'flat') {
            return max(0, $price - $this->value);
        }

        // percentage
        return max(0, $price - ($price * ($this->value / 100)));
    }
}
