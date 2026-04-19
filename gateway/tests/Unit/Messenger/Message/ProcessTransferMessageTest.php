<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger\Message;

use App\Messenger\Message\ProcessTransferMessage;
use PHPUnit\Framework\TestCase;

class ProcessTransferMessageTest extends TestCase
{
    public function testConstructorStoresTransferId(): void
    {
        $message = new ProcessTransferMessage('uuid-abc');

        $this->assertSame('uuid-abc', $message->getTransferId());
    }

    public function testGetTransferIdReturnsCorrectValue(): void
    {
        $message = new ProcessTransferMessage('different-id');

        $this->assertSame('different-id', $message->getTransferId());
    }
}
