<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * A Facebook page a dropshipper connected. page_access_token is stored encrypted and is hidden from
 * every JSON response — use plainToken() on the server only.
 */
class FacebookPage extends Model
{
    use HasFactory;

    public const STATUS_CONNECTED = 'connected';
    public const STATUS_NEEDS_RECONNECT = 'needs_reconnect';
    public const STATUS_DISCONNECTED = 'disconnected';

    protected $fillable = [
        'user_id',
        'facebook_account_id',
        'page_id',
        'page_name',
        'page_access_token',
        'category',
        'status',
        'picture_url',
        'last_error',
        'connected_at',
        'updated_at',
    ];

    protected $hidden = ['page_access_token'];

    protected $casts = [
        'connected_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public $timestamps = false;

    /** The decrypted page token (older rows may hold a plain token). */
    public function plainToken(): ?string
    {
        $token = (string) $this->page_access_token;
        if ($token === '') {
            return null;
        }
        try {
            return Crypt::decryptString($token);
        } catch (\Throwable) {
            return $token;
        }
    }

    public function isUsable(): bool
    {
        return ($this->status ?? self::STATUS_CONNECTED) === self::STATUS_CONNECTED && $this->plainToken() !== null;
    }

    /**
     * Facebook stopped accepting this page's token: mark it and tell the dropshipper once
     * (only when the status changes, so repeated failures don't spam them).
     */
    public function markNeedsReconnect(?string $error = null): void
    {
        $was = $this->status ?: self::STATUS_CONNECTED;
        $this->update([
            'status' => self::STATUS_NEEDS_RECONNECT,
            'last_error' => $error ? mb_substr($error, 0, 480) : $this->last_error,
            'updated_at' => now(),
        ]);
        if ($was === self::STATUS_CONNECTED) {
            app(\App\Service\NotificationService::class)->createNotificationSafely([
                'title' => 'Facebook পেজ আবার কানেক্ট করুন',
                'subtitle' => "\"{$this->page_name}\" পেজে আর পোস্ট করা যাচ্ছে না — Facebook অনুমতি বাতিল করেছে। Facebook পেজ মেনু থেকে আবার কানেক্ট করুন।",
                'created_by' => null, 'send_to' => $this->user_id, 'is_seen' => false,
                'type' => 'facebook', 'is_active' => true, 'image' => null, 'module' => 'facebook',
            ]);
        }
    }

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
