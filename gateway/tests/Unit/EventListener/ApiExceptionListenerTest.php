<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\ApiExceptionListener;
use App\Exception\IdempotencyConflictException;
use App\Exception\InsufficientFundsException;
use App\Exception\SameAccountTransferException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class ApiExceptionListenerTest extends TestCase
{
    private ApiExceptionListener $listener;

    protected function setUp(): void
    {
        $this->listener = new ApiExceptionListener(new NullLogger());
    }

    public function testMapsInsufficientFundsTo422(): void
    {
        $response = $this->listener->buildResponse(new InsufficientFundsException());

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertSame('insufficient_funds', $payload['code']);
        $this->assertSame('Insufficient funds.', $payload['error']);
    }

    public function testMapsSameAccountTransferTo400(): void
    {
        $response = $this->listener->buildResponse(new SameAccountTransferException());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertSame('same_account_transfer', $payload['code']);
    }

    public function testMapsIdempotencyConflictTo409(): void
    {
        $response = $this->listener->buildResponse(new IdempotencyConflictException());

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertSame('idempotency_conflict', $payload['code']);
    }

    public function testMapsHttpExceptionToCorrectStatus(): void
    {
        $response = $this->listener->buildResponse(new BadRequestHttpException('bad input'));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertSame('bad input', $payload['error']);
    }

    public function testMapsNotFoundTo404(): void
    {
        $response = $this->listener->buildResponse(new NotFoundHttpException('no route'));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testGenericExceptionReturns500WithoutLeakingMessage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $listener = new ApiExceptionListener($logger);
        $response = $listener->buildResponse(new \RuntimeException('something sensitive'));

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertSame('Internal server error.', $payload['error']);
        $this->assertStringNotContainsString('sensitive', $response->getContent());
    }

    public function testInvokeSetsResponseOnEvent(): void
    {
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
            new InsufficientFundsException(),
        );

        ($this->listener)($event);

        $this->assertInstanceOf(JsonResponse::class, $event->getResponse());
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $event->getResponse()->getStatusCode());
    }
}
