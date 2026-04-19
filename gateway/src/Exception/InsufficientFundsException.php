<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class InsufficientFundsException extends BusinessException
{
    public function __construct(string $message = 'Insufficient funds.')
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }

    public function errorCode(): string
    {
        return 'insufficient_funds';
    }
}
