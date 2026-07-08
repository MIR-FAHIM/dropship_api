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
        'carryb_business_id',
        'is_active',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function division()
    {
        return $this->belongsTo(Division::class, 'division');
    }
    public function district()
    {
        return $this->belongsTo(District::class, 'district');
    }
    public function carryBeeInfo()
    {
        return $this->hasOne(VendorCarryBeeCredintial::class, 'vendor_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'vendor_id');
    }

    
}
