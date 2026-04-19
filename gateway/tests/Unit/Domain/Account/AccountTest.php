<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Account;

use App\Domain\Account\Account;
use PHPUnit\Framework\TestCase;

class AccountTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $account = new Account('Alice', 5000, 'EUR');

        $this->assertSame('Alice', $account->getHolderName());
        $this->assertSame(5000, $account->getBalance());
        $this->assertSame('EUR', $account->getCurrency());
        $this->assertInstanceOf(\DateTimeImmutable::class, $account->getCreatedAt());
    }

    public function testConstructorDefaults(): void
    {
        $account = new Account('Bob');

        $this->assertSame(0, $account->getBalance());
        $this->assertSame('EUR', $account->getCurrency());
    }

    public function testIdIsNullBeforePersistence(): void
    {
        $account = new Account('Alice', 1000);

        $this->assertNull($account->getId());
    }

    public function testDebitReducesBalance(): void
    {
        $account = new Account('Alice', 5000);

        $account->debit(2000);

        $this->assertSame(3000, $account->getBalance());
    }

    public function testDebitToZeroSucceeds(): void
    {
        $account = new Account('Alice', 3000);

        $account->debit(3000);

        $this->assertSame(0, $account->getBalance());
    }

    public function testDebitThrowsDomainExceptionOnInsufficientFunds(): void
    {
        $account = new Account('Alice', 1000);

        $this->expectException(\App\Exception\InsufficientFundsException::class);
        $this->expectExceptionMessage('Insufficient funds.');

        $account->debit(1001);
    }

    public function testDebitThrowsInvalidArgumentOnZeroAmount(): void
    {
        $account = new Account('Alice', 1000);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Debit amount must be positive.');

        $account->debit(0);
    }

    public function testDebitThrowsInvalidArgumentOnNegativeAmount(): void
    {
        $account = new Account('Alice', 1000);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Debit amount must be positive.');

        $account->debit(-100);
    }

    public function testCreditIncreasesBalance(): void
    {
        $account = new Account('Alice', 1000);

        $account->credit(500);

        $this->assertSame(1500, $account->getBalance());
    }

    public function testCreditThrowsInvalidArgumentOnZeroAmount(): void
    {
        $account = new Account('Alice', 1000);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Credit amount must be positive.');

        $account->credit(0);
    }

    public function testCreditThrowsInvalidArgumentOnNegativeAmount(): void
    {
        $account = new Account('Alice', 1000);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Credit amount must be positive.');

        $account->credit(-100);
    }

    public function testGettersReturnConstructedValues(): void
    {
        $account = new Account('Charlie', 9999, 'USD');

        $this->assertSame('Charlie', $account->getHolderName());
        $this->assertSame(9999, $account->getBalance());
        $this->assertSame('USD', $account->getCurrency());
    }
}
