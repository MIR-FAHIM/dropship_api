<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductAssistantQa extends Model
{
    use HasFactory;

    protected $table = 'product_assistant_qas';

    protected $fillable = [
        'product_id',
        'question',
        'answer',
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
