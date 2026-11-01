<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeedPostReaction extends Model
{
    use HasFactory;

    protected $table = 'feed_post_reactions';

    protected $fillable = [
        'feed_post_id',
        'user_id',
        'type', // like, love, care, haha, wow, sad, angry
    ];

    public function post()
    {
        return $this->belongsTo(FeedPost::class, 'feed_post_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
