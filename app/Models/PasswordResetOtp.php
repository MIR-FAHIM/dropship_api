<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PasswordResetOtp extends Model
{
    use HasFactory;

    protected $table = 'password_reset_otps';

    protected $fillable = [
        'user_id',
        'email',
        'otp_hash',
        'expires_at',
        'attempts',
        'last_sent_at',
        'used_at',
        'ip_address',
        'user_agent',
    ];

    protected $hidden = [
        'otp_hash',
    ];

    protected $casts = [
        'expires_at'   => 'datetime',
        'last_sent_at' => 'datetime',
        'used_at'      => 'datetime',
        'attempts'     => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
