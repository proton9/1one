<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\CreateTransferRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

class CreateTransferRequestTest extends TestCase
{
    private function validate(CreateTransferRequest $req): array
    {
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
        $violations = $validator->validate($req);
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }

    public function testValidPayloadHasNoViolations(): void
    {
        $this->assertEmpty($this->validate(
            new CreateTransferRequest(1, 2, 1500, 'http://example.com/cb'),
        ));
    }

    public function testNullCallbackUrlIsAccepted(): void
    {
        $this->assertEmpty($this->validate(
            new CreateTransferRequest(1, 2, 100, null),
        ));
    }

    public function testNegativeAmountViolates(): void
    {
        $this->assertContains('amount', $this->validate(
            new CreateTransferRequest(1, 2, -1, null),
        ));
    }

    public function testZeroAmountViolates(): void
    {
        $this->assertContains('amount', $this->validate(
            new CreateTransferRequest(1, 2, 0, null),
        ));
    }

    public function testNegativeSourceAccountIdViolates(): void
    {
        $this->assertContains('source_account_id', $this->validate(
            new CreateTransferRequest(-1, 2, 100, null),
        ));
    }

    public function testNegativeDestAccountIdViolates(): void
    {
        $this->assertContains('dest_account_id', $this->validate(
            new CreateTransferRequest(1, -2, 100, null),
        ));
    }

    public function testInvalidCallbackUrlViolates(): void
    {
        $this->assertContains('callback_url', $this->validate(
            new CreateTransferRequest(1, 2, 100, 'not-a-url'),
        ));
    }
}
