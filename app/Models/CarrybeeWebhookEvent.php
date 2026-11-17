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
        'invoice_id',
        'event_time',
        'order_id',
        'mapped_status_id',
        'signature_valid',
        'processing_status',
        'admin_acknowledged',
        'admin_acknowledged_at',
        'disbursed_to',
        'disbursed_amount',
        'attempts',
        'message',
        'request_headers',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'signature_valid' => 'boolean',
        'admin_acknowledged' => 'boolean',
        'admin_acknowledged_at' => 'datetime',
        'disbursed_amount' => 'decimal:2',
        'attempts' => 'integer',
        'request_headers' => 'array',
        'payload' => 'array',
        'event_time' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public const SOURCE_CARRYBEE = 'carrybee';    // a real POST from CarryBee to /carrybee/webhook
    public const SOURCE_SYNC = 'sync';            // our catch-up job asking CarryBee's API
    public const SOURCE_REPROCESS = 'reprocess';  // admin re-ran a stored event
    public const SOURCE_SIMULATOR = 'simulator';  // test board (older versions stored these)
    public const SOURCE_DEV_TEST = 'dev_test';    // Postman / manual dev calls
    public const SOURCE_MANUAL = 'manual';        // admin "mark as paid" in our panel

    /**
     * Where this logged event came from, read from what was stored with it.
     * Only SOURCE_CARRYBEE proves that CarryBee itself is sending webhooks:
     * sync, simulator and manual rows can carry a valid signature and a store id too.
     */
    public function source(): string
    {
        $headers = array_change_key_case((array) ($this->request_headers ?? []), CASE_LOWER);
        $first = fn (string $key) => is_array($headers[$key] ?? null) ? ($headers[$key][0] ?? null) : ($headers[$key] ?? null);

        if (($this->payload['source'] ?? null) === 'manual_admin_action') {
            return self::SOURCE_MANUAL;
        }
        if (array_key_exists('x-resellerbrain-source', $headers)) {
            return $first('x-resellerbrain-source') === 'webhook' ? self::SOURCE_REPROCESS : self::SOURCE_SYNC;
        }
        if (array_key_exists('x-simulated-by', $headers)) {
            return self::SOURCE_SIMULATOR;
        }
        if (array_key_exists('postman-token', $headers)) {
            return self::SOURCE_DEV_TEST;
        }
        // Rows written by our own code without any request (old manual mark-paid rows).
        if ($headers === [] && !$this->signature_valid && $this->store_id === null) {
            return self::SOURCE_MANUAL;
        }

        return self::SOURCE_CARRYBEE;
    }
}
