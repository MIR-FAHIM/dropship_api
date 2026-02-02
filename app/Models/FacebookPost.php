<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FacebookPost extends Model
{
    use HasFactory;

    protected $fillable = [
        'facebook_page_id',
        'product_id',
        'fb_post_id',
        'status',
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
