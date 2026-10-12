<?php

namespace App\Events;

use App\Models\User;
use App\Models\WhatsAppMessageLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WhatsAppMessageReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param string $senderPhone Normalized phone number of the sender
     * @param string $messageText Inbound message body text
     * @param string $providerMessageId Meta wamid
     * @param array $rawMessage Full Meta message object
     * @param User|null $user Matched user account (if phone matches registered user)
     * @param WhatsAppMessageLog|null $log Stored message log
     */
    public function __construct(
        public readonly string $senderPhone,
        public readonly string $messageText,
        public readonly string $providerMessageId,
        public readonly array $rawMessage = [],
        public readonly ?User $user = null,
        public readonly ?WhatsAppMessageLog $log = null,
    ) {
    }
}
