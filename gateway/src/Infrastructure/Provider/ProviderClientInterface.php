<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider;

use App\Domain\Transfer\Transfer;

interface ProviderClientInterface
{
    /**
     * Send a transfer to the upstream payment provider for processing.
     *
     * @return array{status: string, reason?: string}
     */
    public function processTransfer(Transfer $transfer): array;
}
