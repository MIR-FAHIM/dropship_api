<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CarrybeeWebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'fingerprint',
        'event',
        'store_id',
        'consignment_id',
        'merchant_order_id',
        'event_time',
        'order_id',
        'mapped_status_id',
        'signature_valid',
        'processing_status',
        'attempts',
        'message',
        'request_headers',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'signature_valid' => 'boolean',
        'attempts' => 'integer',
        'request_headers' => 'array',
        'payload' => 'array',
        'event_time' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
