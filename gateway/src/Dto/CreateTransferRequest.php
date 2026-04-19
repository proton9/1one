<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload for POST /transfers. Constructor property names match the JSON keys exactly
 * so Symfony's serializer can bind without a custom name converter.
 */
final class CreateTransferRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Positive]
        public readonly int $source_account_id,

        #[Assert\NotNull]
        #[Assert\Positive]
        public readonly int $dest_account_id,

        #[Assert\NotNull]
        #[Assert\Positive]
        public readonly int $amount,

        #[Assert\Url(protocols: ['http', 'https'], requireTld: false)]
        public readonly ?string $callback_url = null,
    ) {}
}
