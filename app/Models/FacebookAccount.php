<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A dropshipper's Facebook login. access_token is the encrypted long-lived user token (never sent out). */
class FacebookAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'fb_user_id',
        'fb_name',
        'access_token',
        'token_expires_at',
        'updated_at',
    ];

    protected $hidden = ['access_token'];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public $timestamps = false;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function pages()
    {
        return $this->hasMany(FacebookPage::class);
    }
}
