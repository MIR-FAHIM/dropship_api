<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use HasFactory;

    protected $hidden = [
        'password',
    ];

    protected $fillable = [
        'shop_name',
        'contact_person',
        'emergency_contact',
        'address',
        'zone',
        'email',
        'password',
        'mobile',
        'whatsapp',
        'owner_name',
        'shop_type',
        'description',
        'is_active',
    ];
}
