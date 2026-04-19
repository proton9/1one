<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\CorrelationIdListener;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class CorrelationIdListenerTest extends TestCase
{
    private function makeRequestEvent(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function makeResponseEvent(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
    }

    private function makeLogRecord(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'test', [], []);
    }

    public function testIncomingHeaderIsPropagated(): void
    {
        $listener = new CorrelationIdListener();

        $request = new Request();
        $request->headers->set('X-Request-ID', 'incoming-42');
        $listener->onRequest($this->makeRequestEvent($request));

        $this->assertSame('incoming-42', $request->attributes->get('correlation_id'));
        $record = $listener($this->makeLogRecord());
        $this->assertSame('incoming-42', $record->extra['correlation_id']);
    }

    public function testIdIsGeneratedWhenHeaderAbsent(): void
    {
        $listener = new CorrelationIdListener();

        $request = new Request();
        $listener->onRequest($this->makeRequestEvent($request));

        $id = $request->attributes->get('correlation_id');
        $this->assertIsString($id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }

    public function testResponseCarriesCorrelationHeader(): void
    {
        $listener = new CorrelationIdListener();

        $request = new Request();
        $request->headers->set('X-Request-ID', 'resp-id');
        $listener->onRequest($this->makeRequestEvent($request));

        $response = new Response();
        $listener->onResponse($this->makeResponseEvent($request, $response));

        $this->assertSame('resp-id', $response->headers->get('X-Request-ID'));
    }

    public function testProcessorDoesNotStampBeforeRequest(): void
    {
        $listener = new CorrelationIdListener();
        $record = $listener($this->makeLogRecord());

        $this->assertArrayNotHasKey('correlation_id', $record->extra);
    }
}
