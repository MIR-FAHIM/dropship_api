<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginSuccessLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'user_type',
        'role',
        'login_type',
        'token_id',
        'token_name',
        'token_expires_at',
        'url',
        'method',
        'ip_address',
        'user_agent',
        'request_data',
        'logged_in_at',
        'created_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'token_id' => 'integer',
        'token_expires_at' => 'datetime',
        'logged_in_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
