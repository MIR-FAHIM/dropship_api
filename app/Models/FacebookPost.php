<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FacebookPost extends Model
{
    use HasFactory;

    // status: published | scheduled | failed (older rows may also have "republished")
    protected $fillable = [
        'feed_post_id',
        'facebook_page_id',
        'product_id',
        'fb_post_id',
        'status',
        'user_id',
        'caption',
        'image_count',
        'scheduled_at',
        'error',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'image_count' => 'integer',
    ];

    public $timestamps = false;

    public function facebookPage()
    {
        return $this->belongsTo(FacebookPage::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
