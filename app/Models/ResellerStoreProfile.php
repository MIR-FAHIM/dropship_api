<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResellerStoreProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'reseller_id',
        'shop_name',
        'logo',
        'phone',
        'whatsapp',
        'address',
        'details',
        'facebook_url',
        'website',
        'theme',
        'status',
    ];

    public function reseller()
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }
}
