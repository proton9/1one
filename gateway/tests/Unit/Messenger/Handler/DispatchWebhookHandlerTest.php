<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger\Handler;

use App\Domain\Account\Account;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferRepository;
use App\Infrastructure\Webhook\WebhookDispatcher;
use App\Messenger\Handler\DispatchWebhookHandler;
use App\Messenger\Message\DispatchWebhookMessage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DispatchWebhookHandlerTest extends TestCase
{
    private function makeHandler(
        ?EntityManagerInterface $em = null,
        ?TransferRepository $transferRepository = null,
        ?WebhookDispatcher $dispatcher = null,
        ?LoggerInterface $logger = null,
    ): DispatchWebhookHandler {
        return new DispatchWebhookHandler(
            $em ?? $this->createStub(EntityManagerInterface::class),
            $transferRepository ?? $this->createStub(TransferRepository::class),
            $dispatcher ?? $this->createStub(WebhookDispatcher::class),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    private function makeTransfer(?string $callbackUrl = 'http://merchant/webhook'): Transfer
    {
        $source = new Account('Alice', 10000);
        $dest = new Account('Bob', 5000);

        return new Transfer($source, $dest, 1500, $callbackUrl);
    }

    private function stubTransferLookup(?Transfer $transfer): TransferRepository
    {
        $repo = $this->createStub(TransferRepository::class);
        $repo->method('find')->willReturn($transfer);

        return $repo;
    }

    public function testTransferNotFoundLogsErrorAndReturns(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $dispatcher = $this->createMock(WebhookDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup(null),
            dispatcher: $dispatcher,
            logger: $logger,
        );

        $handler(new DispatchWebhookMessage('missing-id'));
    }

    public function testNullCallbackUrlReturnsEarly(): void
    {
        $transfer = $this->makeTransfer(null);

        $dispatcher = $this->createMock(WebhookDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup($transfer),
            dispatcher: $dispatcher,
        );

        $handler(new DispatchWebhookMessage($transfer->getId()));
    }

    public function testSuccessfulDispatchLogsInfo(): void
    {
        $transfer = $this->makeTransfer();

        $dispatcher = $this->createMock(WebhookDispatcher::class);
        $dispatcher->expects($this->once())->method('dispatch')->with($transfer);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup($transfer),
            dispatcher: $dispatcher,
            logger: $logger,
        );

        $handler(new DispatchWebhookMessage($transfer->getId()));
    }

    public function testSkipsIfWebhookAlreadyDelivered(): void
    {
        $transfer = $this->makeTransfer();
        $transfer->markWebhookDelivered();

        $dispatcher = $this->createMock(WebhookDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup($transfer),
            dispatcher: $dispatcher,
        );

        $handler(new DispatchWebhookMessage($transfer->getId()));
    }

    public function testDoubleInvocationDispatchesOnlyOnce(): void
    {
        $transfer = $this->makeTransfer();

        $dispatcher = $this->createMock(WebhookDispatcher::class);
        $dispatcher->expects($this->once())->method('dispatch');

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup($transfer),
            dispatcher: $dispatcher,
        );

        $message = new DispatchWebhookMessage($transfer->getId());
        $handler($message);
        $handler($message);
    }

    public function testSuccessfulDispatchMarksDelivered(): void
    {
        $transfer = $this->makeTransfer();

        $dispatcher = $this->createStub(WebhookDispatcher::class);
        $dispatcher->method('dispatch');

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup($transfer),
            dispatcher: $dispatcher,
        );

        $handler(new DispatchWebhookMessage($transfer->getId()));

        $this->assertNotNull($transfer->getWebhookDeliveredAt());
    }

    public function testFailedDispatchDoesNotMarkDelivered(): void
    {
        $transfer = $this->makeTransfer();

        $dispatcher = $this->createStub(WebhookDispatcher::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('boom'));

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup($transfer),
            dispatcher: $dispatcher,
        );

        try {
            $handler(new DispatchWebhookMessage($transfer->getId()));
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull($transfer->getWebhookDeliveredAt());
    }

    public function testDispatcherExceptionLogsWarningAndRethrows(): void
    {
        $transfer = $this->makeTransfer();

        $dispatcher = $this->createStub(WebhookDispatcher::class);
        $dispatcher->method('dispatch')
            ->willThrowException(new \RuntimeException('Connection timeout'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup($transfer),
            dispatcher: $dispatcher,
            logger: $logger,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection timeout');

        $handler(new DispatchWebhookMessage($transfer->getId()));
    }
}
