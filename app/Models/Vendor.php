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
        'logo',
        'banner',
        'description',
        'carryb_store_id',
        'is_active',
        'has_own_delivery_account',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'has_own_delivery_account' => 'boolean',
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

    public function shop()
    {
        return $this->hasOne(Shops::class, 'user_id', 'user_id');
    }

    public function getVendortypeAttribute(): string
    {
        return !empty($this->shop_type) ? (string) $this->shop_type : 'Vendor Store';
    }

    public function getLogoUrlAttribute(): ?string
    {
        if (!empty($this->logo)) {
            if (str_starts_with($this->logo, 'http://') || str_starts_with($this->logo, 'https://')) {
                return $this->logo;
            }
            return asset('storage/' . ltrim($this->logo, '/'));
        }

        if ($this->relationLoaded('shop') && $this->shop) {
            $shopLogo = $this->shop->logo;
            if ($shopLogo instanceof Upload) {
                return $shopLogo->url;
            }
            if (is_string($shopLogo) && !empty($shopLogo)) {
                return str_starts_with($shopLogo, 'http') ? $shopLogo : asset('storage/' . ltrim($shopLogo, '/'));
            }
        }

        if ($this->relationLoaded('user') && $this->user) {
            $avatar = $this->user->avatar_original ?: $this->user->avatar;
            if (!empty($avatar)) {
                return str_starts_with($avatar, 'http') ? $avatar : asset('storage/' . ltrim($avatar, '/'));
            }
        }

        return null;
    }

    public function getBannerUrlAttribute(): ?string
    {
        if (!empty($this->banner)) {
            if (str_starts_with($this->banner, 'http://') || str_starts_with($this->banner, 'https://')) {
                return $this->banner;
            }
            return asset('storage/' . ltrim($this->banner, '/'));
        }

        if ($this->relationLoaded('shop') && $this->shop) {
            $shopBanner = $this->shop->banner;
            if ($shopBanner instanceof Upload) {
                return $shopBanner->url;
            }
            if (is_string($shopBanner) && !empty($shopBanner)) {
                return str_starts_with($shopBanner, 'http') ? $shopBanner : asset('storage/' . ltrim($shopBanner, '/'));
            }
        }

        return null;
    }
}
