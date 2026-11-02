<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PartnerStore extends Model
{
    use HasFactory;

    protected $table = 'partner_stores';

    protected $fillable = [
        'user_id',
        'store_name',
        'store_domain',
        'store_url',
        'api_key',
        'api_secret',
        'webhook_url',
        'webhook_secret',
        'is_active',
        'last_sync_at',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_sync_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'api_secret',
        'webhook_secret',
    ];

    /**
     * Dropshipper owning this store
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Generate unique live API Key
     */
    public static function generateApiKey(): string
    {
        do {
            $key = 'rb_live_pk_' . Str::random(32);
        } while (self::where('api_key', $key)->exists());

        return $key;
    }

    /**
     * Generate live API Secret
     */
    public static function generateApiSecret(): string
    {
        return 'rb_live_sk_' . Str::random(48);
    }

    /**
     * Generate Webhook Secret
     */
    public static function generateWebhookSecret(): string
    {
        return 'rb_whsec_' . Str::random(32);
    }
}
