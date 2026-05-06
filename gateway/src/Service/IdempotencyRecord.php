<?php

declare(strict_types=1);

namespace App\Service;

final readonly class IdempotencyRecord
{
    public function __construct(
        public string $transferId,
        public ?string $fingerprint,
    ) {}
}
