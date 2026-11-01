<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeedPostComment extends Model
{
    use HasFactory;

    protected $table = 'feed_post_comments';

    protected $fillable = [
        'feed_post_id',
        'user_id',
        'parent_id',
        'comment',
        'status',
    ];

    public function post()
    {
        return $this->belongsTo(FeedPost::class, 'feed_post_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent()
    {
        return $this->belongsTo(FeedPostComment::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(FeedPostComment::class, 'parent_id')->with('user:id,name,avatar,role,user_type')->latest();
    }
}
