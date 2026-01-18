<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
        'mobile',
        'optional_phone',
        'address',
        'fcm_token',
        'is_banned',
        'role',
        'status',
        'zone',
        'district',
        'area',
        'lat',
        'lon',
    ];

    /**
     * The attributes that should be hidden for arrays / JSON.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_banned' => 'boolean',
        'lat' => 'decimal:7',
        'lon' => 'decimal:7',
    ];

    public function apiTokens()
    {
        return $this->hasMany(ApiToken::class);
    }
}
