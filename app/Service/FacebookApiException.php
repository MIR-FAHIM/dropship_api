<?php

namespace App\Service;

use RuntimeException;

/** A Facebook Graph error; needsReconnect() tells whether the dropshipper must connect the page again. */
class FacebookApiException extends RuntimeException
{
    public function needsReconnect(): bool
    {
        return in_array($this->getCode(), FacebookGraphService::RECONNECT_CODES, true);
    }
}
