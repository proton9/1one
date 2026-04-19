<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class IdempotencyConflictException extends BusinessException
{
    public function __construct(string $message = 'Idempotency key is currently in flight. Retry shortly.')
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }

    public function errorCode(): string
    {
        return 'idempotency_conflict';
    }
}
