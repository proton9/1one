<?php

declare(strict_types=1);

namespace App\EventListener;

use Monolog\LogRecord;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Reads `X-Request-ID` from the incoming request (generating one if missing),
 * pushes it into every log record via Monolog's extra bag, and echoes it back on
 * the response so callers can correlate their logs with ours.
 */
final class CorrelationIdListener
{
    private const HEADER = 'X-Request-ID';

    private ?string $currentId = null;

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 250)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $this->currentId = $request->headers->get(self::HEADER) ?? Uuid::v4()->toRfc4122();
        $request->attributes->set('correlation_id', $this->currentId);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $this->currentId === null) {
            return;
        }

        $event->getResponse()->headers->set(self::HEADER, $this->currentId);
    }

    /**
     * Monolog processor callback: invoked on every log record, stamps the current
     * correlation id into `extra`.
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        if ($this->currentId !== null) {
            $record->extra['correlation_id'] = $this->currentId;
        }

        return $record;
    }
}
