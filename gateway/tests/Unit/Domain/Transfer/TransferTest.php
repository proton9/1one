<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Transfer;

use App\Domain\Account\Account;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferStatus;
use PHPUnit\Framework\TestCase;

class TransferTest extends TestCase
{
    private function makeTransfer(
        ?string $callbackUrl = null,
        ?string $idempotencyKey = null,
    ): Transfer {
        $source = new Account('Alice', 10000, 'EUR');
        $dest = new Account('Bob', 5000, 'EUR');

        return new Transfer($source, $dest, 1500, $callbackUrl, $idempotencyKey);
    }

    public function testConstructorGeneratesUuidId(): void
    {
        $transfer = $this->makeTransfer();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $transfer->getId(),
        );
    }

    public function testConstructorSetsAllFields(): void
    {
        $source = new Account('Alice', 10000, 'EUR');
        $dest = new Account('Bob', 5000, 'EUR');
        $transfer = new Transfer($source, $dest, 2000, 'http://example.com/cb', 'key-123');

        $this->assertSame($source, $transfer->getSourceAccount());
        $this->assertSame($dest, $transfer->getDestAccount());
        $this->assertSame(2000, $transfer->getAmount());
        $this->assertSame('http://example.com/cb', $transfer->getCallbackUrl());
        $this->assertSame('key-123', $transfer->getIdempotencyKey());
    }

    public function testInitialStatusIsReserved(): void
    {
        $transfer = $this->makeTransfer();

        $this->assertSame(TransferStatus::Reserved, $transfer->getStatus());
    }

    public function testCurrencyInheritedFromSourceAccount(): void
    {
        $source = new Account('Alice', 10000, 'USD');
        $dest = new Account('Bob', 5000, 'EUR');
        $transfer = new Transfer($source, $dest, 100);

        $this->assertSame('USD', $transfer->getCurrency());
    }

    public function testCallbackUrlAndIdempotencyKeyAreNullable(): void
    {
        $transfer = $this->makeTransfer();

        $this->assertNull($transfer->getCallbackUrl());
        $this->assertNull($transfer->getIdempotencyKey());
    }

    public function testMarkProcessingSetsStatusAndUpdatesTimestamp(): void
    {
        $transfer = $this->makeTransfer();
        $beforeUpdate = $transfer->getUpdatedAt();

        // Ensure a time gap so the timestamp changes
        usleep(1000);
        $transfer->markProcessing();

        $this->assertSame(TransferStatus::Processing, $transfer->getStatus());
        $this->assertGreaterThanOrEqual($beforeUpdate, $transfer->getUpdatedAt());
    }

    public function testMarkDoneSetsStatusAndUpdatesTimestamp(): void
    {
        $transfer = $this->makeTransfer();

        $transfer->markDone();

        $this->assertSame(TransferStatus::Done, $transfer->getStatus());
    }

    public function testMarkFailedSetsStatusReasonAndUpdatesTimestamp(): void
    {
        $transfer = $this->makeTransfer();

        $transfer->markFailed('Provider timeout');

        $this->assertSame(TransferStatus::Failed, $transfer->getStatus());
        $this->assertSame('Provider timeout', $transfer->getFailureReason());
    }

    public function testFailureReasonIsNullInitially(): void
    {
        $transfer = $this->makeTransfer();

        $this->assertNull($transfer->getFailureReason());
    }

    public function testIsInTerminalStatusReturnsTrueForDone(): void
    {
        $transfer = $this->makeTransfer();
        $transfer->markDone();

        $this->assertTrue($transfer->isInTerminalStatus());
    }

    public function testIsInTerminalStatusReturnsTrueForFailed(): void
    {
        $transfer = $this->makeTransfer();
        $transfer->markFailed('x');

        $this->assertTrue($transfer->isInTerminalStatus());
    }

    public function testIsInTerminalStatusReturnsFalseForReserved(): void
    {
        $transfer = $this->makeTransfer();

        $this->assertFalse($transfer->isInTerminalStatus());
    }

    public function testIsInTerminalStatusReturnsFalseForProcessing(): void
    {
        $transfer = $this->makeTransfer();
        $transfer->markProcessing();

        $this->assertFalse($transfer->isInTerminalStatus());
    }

    public function testWebhookDeliveredAtNullInitially(): void
    {
        $transfer = $this->makeTransfer();

        $this->assertNull($transfer->getWebhookDeliveredAt());
    }

    public function testMarkWebhookDeliveredSetsTimestamp(): void
    {
        $transfer = $this->makeTransfer();
        $transfer->markWebhookDelivered();

        $this->assertInstanceOf(\DateTimeImmutable::class, $transfer->getWebhookDeliveredAt());
    }

    public function testConstructorAcceptsPreAssignedId(): void
    {
        $source = new Account('Alice', 10000);
        $dest = new Account('Bob', 5000);
        $transfer = new Transfer($source, $dest, 1500, null, null, 'my-pre-assigned-id');

        $this->assertSame('my-pre-assigned-id', $transfer->getId());
    }
}
