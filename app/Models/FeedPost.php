<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeedPost extends Model
{
    use HasFactory;

    protected $table = 'feed_posts';

    protected $fillable = [
        'admin_id',
        'product_id',
        'title',
        'content',
        'images',
        'status',
        'likes_count',
        'comments_count',
        'shares_count',
        'metadata',
    ];

    protected $casts = [
        'images' => 'array',
        'metadata' => 'array',
        'likes_count' => 'integer',
        'comments_count' => 'integer',
        'shares_count' => 'integer',
    ];

    /**
     * Relationship: Admin who created the post
     */
    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * Relationship: Associated Product
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Relationship: Reactions on this post
     */
    public function reactions()
    {
        return $this->hasMany(FeedPostReaction::class, 'feed_post_id');
    }

    /**
     * Relationship: All comments on this post
     */
    public function comments()
    {
        return $this->hasMany(FeedPostComment::class, 'feed_post_id');
    }

    /**
     * Relationship: Top-level comments only (for nested tree display)
     */
    public function rootComments()
    {
        return $this->hasMany(FeedPostComment::class, 'feed_post_id')->whereNull('parent_id')->latest();
    }

    /**
     * Relationship: External Facebook published shares
     */
    public function facebookShares()
    {
        return $this->hasMany(FacebookPost::class, 'feed_post_id');
    }

    /**
     * Get reaction counts breakdown by type (like, love, care, etc.)
     */
    public function getReactionCounts()
    {
        $breakdown = $this->reactions()
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        return array_merge([
            'like' => 0,
            'love' => 0,
            'care' => 0,
            'haha' => 0,
            'wow' => 0,
            'sad' => 0,
            'angry' => 0,
        ], $breakdown);
    }
}
