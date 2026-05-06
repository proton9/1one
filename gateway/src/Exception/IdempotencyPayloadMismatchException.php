<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class IdempotencyPayloadMismatchException extends BusinessException
{
    public function __construct(string $message = 'Idempotency key was previously used with a different request body.')
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }

    public function errorCode(): string
    {
        return 'idempotency_payload_mismatch';
    }
}
