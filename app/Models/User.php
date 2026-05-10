<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'users';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'referred_by',
        'provider',
        'provider_id',
        'refresh_token',
        'access_token',
        'user_type',
        'name',
        'email',
        'email_verified_at',
        'verification_code',
        'new_email_verificiation_code',
        'password',
        'remember_token',
        'device_token',
        'avatar',
        'avatar_original',
        'address',
        'country',
        'state',
        'city',
        'postal_code',
        'phone',
        'balance',
        'banned',
        'role',
        'referral_code',
        'customer_package_id',
        'remaining_uploads',
    ];

    /**
     * Attributes hidden from arrays / JSON.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'refresh_token',
        'access_token',
        'verification_code',
        'new_email_verificiation_code',
    ];

    /**
     * Attribute casting.
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'balance' => 'float',
        'banned' => 'integer',
        'remaining_uploads' => 'integer',
        'customer_package_id' => 'integer',
        'referred_by' => 'integer',
    ];

    /**
     * Self reference: who referred this user.
     */
    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    /**
     * Users referred by this user.
     */
    public function referrals()
    {
        return $this->hasMany(User::class, 'referred_by');
    }
    public function apiTokens()
    {
        return $this->hasMany(ApiToken::class);
    }

    public function vendor()
    {
        return $this->hasOne(Vendor::class);
    }
}
