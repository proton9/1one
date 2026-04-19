<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger\Message;

use App\Messenger\Message\DispatchWebhookMessage;
use PHPUnit\Framework\TestCase;

class DispatchWebhookMessageTest extends TestCase
{
    public function testConstructorStoresTransferId(): void
    {
        $message = new DispatchWebhookMessage('uuid-xyz');

        $this->assertSame('uuid-xyz', $message->getTransferId());
    }

    public function testGetTransferIdReturnsCorrectValue(): void
    {
        $message = new DispatchWebhookMessage('another-id');

        $this->assertSame('another-id', $message->getTransferId());
    }
}
