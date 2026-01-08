<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryAddress extends Model
{
    use HasFactory;

    protected $table = 'delivery_addresses';

    /**
     * Mass assignable fields
     */
    protected $fillable = [
        'user_id',
        'name',
        'mobile',
        'address',
        'district',
        'area',
        'house',
        'flat',
        'lat',
        'lon',
        'note',
        'status',
    ];

    /**
     * Cast attributes
     */
    protected $casts = [
        'status' => 'boolean',
        'lat'    => 'float',
        'lon'    => 'float',
    ];
}
