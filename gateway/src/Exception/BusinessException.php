<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Base class for exceptions that represent a known, business-meaningful failure
 * the API can surface to the caller. Each subclass maps to a specific HTTP status
 * and a stable machine-readable error code; the ApiExceptionListener turns them
 * into JSON responses, so controllers don't need try/catch around service calls.
 */
abstract class BusinessException extends \RuntimeException
{
    abstract public function httpStatus(): int;

    abstract public function errorCode(): string;
}
