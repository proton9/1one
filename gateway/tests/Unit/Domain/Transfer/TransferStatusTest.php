<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Transfer;

use App\Domain\Transfer\TransferStatus;
use PHPUnit\Framework\TestCase;

class TransferStatusTest extends TestCase
{
    public function testEnumHasExactlyFourCases(): void
    {
        $this->assertCount(4, TransferStatus::cases());
    }

    public function testBackedValuesMatchExpectedStrings(): void
    {
        $this->assertSame('reserved', TransferStatus::Reserved->value);
        $this->assertSame('processing', TransferStatus::Processing->value);
        $this->assertSame('done', TransferStatus::Done->value);
        $this->assertSame('failed', TransferStatus::Failed->value);
    }

    public function testFromWorksForAllValidStrings(): void
    {
        $this->assertSame(TransferStatus::Reserved, TransferStatus::from('reserved'));
        $this->assertSame(TransferStatus::Processing, TransferStatus::from('processing'));
        $this->assertSame(TransferStatus::Done, TransferStatus::from('done'));
        $this->assertSame(TransferStatus::Failed, TransferStatus::from('failed'));
    }

    public function testFromThrowsOnInvalidString(): void
    {
        $this->expectException(\ValueError::class);

        TransferStatus::from('invalid');
    }
}
