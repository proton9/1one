<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Ledger;

use App\Domain\Ledger\LedgerDirection;
use PHPUnit\Framework\TestCase;

class LedgerDirectionTest extends TestCase
{
    public function testEnumHasExactlyTwoCases(): void
    {
        $this->assertCount(2, LedgerDirection::cases());
    }

    public function testBackedValues(): void
    {
        $this->assertSame('debit', LedgerDirection::Debit->value);
        $this->assertSame('credit', LedgerDirection::Credit->value);
    }

    public function testFromWorksForValidValues(): void
    {
        $this->assertSame(LedgerDirection::Debit, LedgerDirection::from('debit'));
        $this->assertSame(LedgerDirection::Credit, LedgerDirection::from('credit'));
    }

    public function testFromThrowsOnInvalid(): void
    {
        $this->expectException(\ValueError::class);

        LedgerDirection::from('transfer');
    }
}
