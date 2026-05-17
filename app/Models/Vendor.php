<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'shop_name',
        'contact_person',
        'emergency_contact',
        'division',
        'district',
        'zone',
        'whatsapp',
        'owner_name',
        'shop_type',
        'description',
        'is_active',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
