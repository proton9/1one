<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\BusinessException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

#[AsEventListener(event: ExceptionEvent::class)]
final class ApiExceptionListener
{
    public function __construct(private LoggerInterface $logger) {}

    public function __invoke(ExceptionEvent $event): void
    {
        $event->setResponse($this->buildResponse($event->getThrowable()));
    }

    /**
     * Exposed for unit testing — maps any throwable to the JSON response the API returns.
     */
    public function buildResponse(\Throwable $e): JsonResponse
    {
        if ($e instanceof BusinessException) {
            return new JsonResponse(
                ['error' => $e->getMessage(), 'code' => $e->errorCode()],
                $e->httpStatus(),
            );
        }

        if ($e instanceof HttpExceptionInterface) {
            // Validator/MapRequestPayload failures, 404s, 405s, etc.
            return new JsonResponse(
                ['error' => $e->getMessage() !== '' ? $e->getMessage() : Response::$statusTexts[$e->getStatusCode()] ?? 'Error'],
                $e->getStatusCode(),
            );
        }

        $this->logger->error('Unhandled exception', [
            'exception' => $e->getMessage(),
            'class' => $e::class,
        ]);

        return new JsonResponse(
            ['error' => 'Internal server error.'],
            Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }
}
