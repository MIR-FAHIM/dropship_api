<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorCarryBeeCredintial extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_id',
        'base_url',
        'client_id',
        'client_secret',
        'client_context',
        'is_active',
        'created_by',
        'note',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function vendor()
    {
        return $this->belongsTo(User::class, 'vendor_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
