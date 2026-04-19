<?php

declare(strict_types=1);

namespace App\Messenger\Message;

class DispatchWebhookMessage
{
    public function __construct(
        private string $transferId,
    ) {}

    public function getTransferId(): string
    {
        return $this->transferId;
    }
}
