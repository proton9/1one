<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class SameAccountTransferException extends BusinessException
{
    public function __construct(string $message = 'Source and destination accounts must differ.')
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }

    public function errorCode(): string
    {
        return 'same_account_transfer';
    }
}
