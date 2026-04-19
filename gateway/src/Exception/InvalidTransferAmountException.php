<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class InvalidTransferAmountException extends BusinessException
{
    public function __construct(string $message = 'Transfer amount must be positive.')
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }

    public function errorCode(): string
    {
        return 'invalid_transfer_amount';
    }
}
