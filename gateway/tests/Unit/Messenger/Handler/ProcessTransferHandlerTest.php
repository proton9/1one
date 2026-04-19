<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger\Handler;

use App\Domain\Account\Account;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferRepository;
use App\Domain\Transfer\TransferStatus;
use App\Infrastructure\Provider\ProviderClientInterface;
use App\Messenger\Handler\ProcessTransferHandler;
use App\Messenger\Message\DispatchWebhookMessage;
use App\Messenger\Message\ProcessTransferMessage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class ProcessTransferHandlerTest extends TestCase
{
    private function makeHandler(
        ?EntityManagerInterface $em = null,
        ?TransferRepository $transferRepository = null,
        ?ProviderClientInterface $provider = null,
        ?MessageBusInterface $bus = null,
        ?LoggerInterface $logger = null,
    ): ProcessTransferHandler {
        return new ProcessTransferHandler(
            $em ?? $this->createStub(EntityManagerInterface::class),
            $transferRepository ?? $this->createStub(TransferRepository::class),
            $provider ?? $this->createStub(ProviderClientInterface::class),
            $bus ?? $this->createStub(MessageBusInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    private function makeTransfer(?string $callbackUrl = 'http://merchant/webhook'): Transfer
    {
        $source = new Account('Alice', 10000);
        $dest = new Account('Bob', 5000);
        $ref = new \ReflectionProperty(Account::class, 'id');
        $ref->setValue($source, 1);
        $ref->setValue($dest, 2);

        return new Transfer($source, $dest, 1500, $callbackUrl);
    }

    private function stubTransferLookup(?Transfer $transfer): TransferRepository
    {
        $repo = $this->createStub(TransferRepository::class);
        $repo->method('find')->willReturn($transfer);

        return $repo;
    }

    // --- Transfer not found ---

    public function testTransferNotFoundLogsErrorAndReturns(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $provider = $this->createMock(ProviderClientInterface::class);
        $provider->expects($this->never())->method('processTransfer');

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup(null),
            provider: $provider,
            logger: $logger,
        );

        $handler(new ProcessTransferMessage('missing-id'));
    }

    // --- Provider returns completed ---

    public function testProviderCompletedMarksDoneAndDispatchesWebhook(): void
    {
        $transfer = $this->makeTransfer();

        $provider = $this->createStub(ProviderClientInterface::class);
        $provider->method('processTransfer')->willReturn(['status' => 'completed']);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(DispatchWebhookMessage::class))
            ->willReturnCallback(fn ($msg) => new Envelope($msg));

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup($transfer),
            provider: $provider,
            bus: $bus,
        );

        $handler(new ProcessTransferMessage($transfer->getId()));

        $this->assertSame(TransferStatus::Done, $transfer->getStatus());
    }

    // --- Provider returns non-completed ---

    public function testProviderRejectionMarksFailedWithReason(): void
    {
        $transfer = $this->makeTransfer();

        $provider = $this->createStub(ProviderClientInterface::class);
        $provider->method('processTransfer')
            ->willReturn(['status' => 'rejected', 'reason' => 'Limit exceeded']);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(fn ($msg) => new Envelope($msg));

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup($transfer),
            provider: $provider,
            bus: $bus,
        );

        $handler(new ProcessTransferMessage($transfer->getId()));

        $this->assertSame(TransferStatus::Failed, $transfer->getStatus());
        $this->assertSame('Limit exceeded', $transfer->getFailureReason());
    }

    public function testProviderRejectionWithoutReasonUsesDefault(): void
    {
        $transfer = $this->makeTransfer();

        $provider = $this->createStub(ProviderClientInterface::class);
        $provider->method('processTransfer')->willReturn(['status' => 'rejected']);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(fn ($msg) => new Envelope($msg));

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup($transfer),
            provider: $provider,
            bus: $bus,
        );

        $handler(new ProcessTransferMessage($transfer->getId()));

        $this->assertSame('Provider rejected the transfer.', $transfer->getFailureReason());
    }

    // --- No callback URL ---

    public function testNoCallbackUrlSkipsWebhookOnSuccess(): void
    {
        $transfer = $this->makeTransfer(null);

        $provider = $this->createStub(ProviderClientInterface::class);
        $provider->method('processTransfer')->willReturn(['status' => 'completed']);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup($transfer),
            provider: $provider,
            bus: $bus,
        );

        $handler(new ProcessTransferMessage($transfer->getId()));

        $this->assertSame(TransferStatus::Done, $transfer->getStatus());
    }

    public function testNoCallbackUrlSkipsWebhookOnFailure(): void
    {
        $transfer = $this->makeTransfer(null);

        $provider = $this->createStub(ProviderClientInterface::class);
        $provider->method('processTransfer')
            ->willReturn(['status' => 'rejected', 'reason' => 'Bad']);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup($transfer),
            provider: $provider,
            bus: $bus,
        );

        $handler(new ProcessTransferMessage($transfer->getId()));

        $this->assertSame(TransferStatus::Failed, $transfer->getStatus());
    }

    // --- Provider throws exception ---

    public function testProviderExceptionMarksFailedAndRethrows(): void
    {
        $transfer = $this->makeTransfer();

        $provider = $this->createStub(ProviderClientInterface::class);
        $provider->method('processTransfer')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(fn ($msg) => new Envelope($msg));

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup($transfer),
            provider: $provider,
            bus: $bus,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection refused');

        $handler(new ProcessTransferMessage($transfer->getId()));
    }

    public function testProviderExceptionWithNoCallbackDoesNotDispatchWebhook(): void
    {
        $transfer = $this->makeTransfer(null);

        $provider = $this->createStub(ProviderClientInterface::class);
        $provider->method('processTransfer')
            ->willThrowException(new \RuntimeException('Timeout'));

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup($transfer),
            provider: $provider,
            bus: $bus,
        );

        try {
            $handler(new ProcessTransferMessage($transfer->getId()));
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(TransferStatus::Failed, $transfer->getStatus());
    }

    // --- Idempotency: handler is safe to re-run ---

    public function testSkipsTransferAlreadyInDoneStatus(): void
    {
        $transfer = $this->makeTransfer();
        $transfer->markDone();

        $provider = $this->createMock(ProviderClientInterface::class);
        $provider->expects($this->never())->method('processTransfer');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup($transfer),
            provider: $provider,
            bus: $bus,
        );

        $handler(new ProcessTransferMessage($transfer->getId()));

        $this->assertSame(TransferStatus::Done, $transfer->getStatus());
    }

    public function testSkipsTransferAlreadyInFailedStatus(): void
    {
        $transfer = $this->makeTransfer();
        $transfer->markFailed('earlier error');

        $provider = $this->createMock(ProviderClientInterface::class);
        $provider->expects($this->never())->method('processTransfer');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup($transfer),
            provider: $provider,
            bus: $bus,
        );

        $handler(new ProcessTransferMessage($transfer->getId()));

        $this->assertSame(TransferStatus::Failed, $transfer->getStatus());
        $this->assertSame('earlier error', $transfer->getFailureReason());
    }

    public function testDoubleInvocationCallsProviderOnlyOnce(): void
    {
        $transfer = $this->makeTransfer();

        $provider = $this->createMock(ProviderClientInterface::class);
        $provider->expects($this->once())
            ->method('processTransfer')
            ->willReturn(['status' => 'completed']);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(fn ($msg) => new Envelope($msg));

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup($transfer),
            provider: $provider,
            bus: $bus,
        );

        $message = new ProcessTransferMessage($transfer->getId());
        $handler($message);
        $handler($message);
    }

    // --- Status set to processing before provider call ---

    public function testTransferMarkedProcessingBeforeProviderCall(): void
    {
        $transfer = $this->makeTransfer();

        $statusDuringCall = null;
        $provider = $this->createStub(ProviderClientInterface::class);
        $provider->method('processTransfer')
            ->willReturnCallback(function () use ($transfer, &$statusDuringCall) {
                $statusDuringCall = $transfer->getStatus();

                return ['status' => 'completed'];
            });

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(fn ($msg) => new Envelope($msg));

        $handler = $this->makeHandler(
            transferRepository: $this->stubTransferLookup($transfer),
            provider: $provider,
            bus: $bus,
        );

        $handler(new ProcessTransferMessage($transfer->getId()));

        $this->assertSame(TransferStatus::Processing, $statusDuringCall);
    }
}
