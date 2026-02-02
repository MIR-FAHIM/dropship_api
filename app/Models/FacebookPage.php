<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FacebookPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'facebook_account_id',
        'page_id',
        'page_name',
        'page_access_token',
        'category',
    ];

    public $timestamps = false;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function facebookAccount()
    {
        return $this->belongsTo(FacebookAccount::class);
    }

    public function posts()
    {
        return $this->hasMany(FacebookPost::class);
    }
}
