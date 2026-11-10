<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketerInvitation extends Model
{
    protected $fillable = ['marketer_user_id', 'email', 'token_hash', 'expires_at', 'revoked_at', 'marketer_client_id'];

    protected $hidden = ['token_hash'];

    protected $casts = ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
}
