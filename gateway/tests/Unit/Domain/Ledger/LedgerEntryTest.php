<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Ledger;

use App\Domain\Account\Account;
use App\Domain\Ledger\LedgerDirection;
use App\Domain\Ledger\LedgerEntry;
use App\Domain\Transfer\Transfer;
use PHPUnit\Framework\TestCase;

class LedgerEntryTest extends TestCase
{
    private function makeLedgerEntry(LedgerDirection $direction = LedgerDirection::Debit): LedgerEntry
    {
        $account = new Account('Alice', 10000);
        $transfer = new Transfer($account, new Account('Bob', 5000), 1000);

        return new LedgerEntry($transfer, $account, $direction, 1000);
    }

    public function testConstructorSetsAllFields(): void
    {
        $account = new Account('Alice', 10000);
        $dest = new Account('Bob', 5000);
        $transfer = new Transfer($account, $dest, 2500);

        $entry = new LedgerEntry($transfer, $account, LedgerDirection::Debit, 2500);

        $this->assertSame($transfer, $entry->getTransfer());
        $this->assertSame($account, $entry->getAccount());
        $this->assertSame(LedgerDirection::Debit, $entry->getDirection());
        $this->assertSame(2500, $entry->getAmount());
        $this->assertInstanceOf(\DateTimeImmutable::class, $entry->getCreatedAt());
    }

    public function testIdIsNullBeforePersistence(): void
    {
        $this->assertNull($this->makeLedgerEntry()->getId());
    }

    public function testCreditDirection(): void
    {
        $entry = $this->makeLedgerEntry(LedgerDirection::Credit);

        $this->assertSame(LedgerDirection::Credit, $entry->getDirection());
        $this->assertSame('credit', $entry->getDirection()->value);
    }

    public function testDebitDirection(): void
    {
        $entry = $this->makeLedgerEntry(LedgerDirection::Debit);

        $this->assertSame('debit', $entry->getDirection()->value);
    }
}
