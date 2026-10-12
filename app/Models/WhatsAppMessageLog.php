<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessageLog extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_message_logs';

    protected $fillable = [
        'direction',
        'user_id',
        'phone_number',
        'message_type',
        'template_name',
        'template_language',
        'message_content',
        'parameters',
        'provider_message_id',
        'status',
        'error_code',
        'error_message',
        'attempts',
        'raw_payload',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
    ];

    protected $casts = [
        'parameters' => 'array',
        'raw_payload' => 'array',
        'attempts' => 'integer',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markAsSent(string $providerMessageId, ?array $rawPayload = null): void
    {
        $this->update([
            'provider_message_id' => $providerMessageId,
            'status' => 'sent',
            'sent_at' => now(),
            'raw_payload' => $rawPayload ?? $this->raw_payload,
        ]);
    }

    public function markAsDelivered(?array $rawPayload = null): void
    {
        $this->update([
            'status' => 'delivered',
            'delivered_at' => now(),
            'raw_payload' => $rawPayload ?? $this->raw_payload,
        ]);
    }

    public function markAsRead(?array $rawPayload = null): void
    {
        $this->update([
            'status' => 'read',
            'read_at' => now(),
            'raw_payload' => $rawPayload ?? $this->raw_payload,
        ]);
    }

    public function markAsFailed(?string $code = null, ?string $message = null, ?array $rawPayload = null): void
    {
        $this->update([
            'status' => 'failed',
            'error_code' => $code,
            'error_message' => $message,
            'failed_at' => now(),
            'raw_payload' => $rawPayload ?? $this->raw_payload,
        ]);
    }
}
