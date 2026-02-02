<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FacebookAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'fb_user_id',
        'fb_name',
    ];

    public $timestamps = false;

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
