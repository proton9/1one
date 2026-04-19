<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class AccountNotFoundException extends BusinessException
{
    public function __construct(string $message = 'One or both accounts do not exist.')
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }

    public function errorCode(): string
    {
        return 'account_not_found';
    }
}
