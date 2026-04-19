<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Domain\Account\Account;
use App\Domain\Account\AccountRepository;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferRepository;
use App\Domain\Transfer\TransferStatus;
use App\Messenger\Message\ProcessTransferMessage;
use App\Service\IdempotencyService;
use App\Service\TransferService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class TransferServiceTest extends TestCase
{
    private function makeService(
        ?EntityManagerInterface $em = null,
        ?TransferRepository $transferRepository = null,
        ?AccountRepository $accountRepository = null,
        ?MessageBusInterface $bus = null,
        ?IdempotencyService $idempotency = null,
        ?LoggerInterface $logger = null,
    ): TransferService {
        return new TransferService(
            $em ?? $this->createStub(EntityManagerInterface::class),
            $transferRepository ?? $this->createStub(TransferRepository::class),
            $accountRepository ?? $this->createStub(AccountRepository::class),
            $bus ?? $this->createStub(MessageBusInterface::class),
            $idempotency ?? $this->createStub(IdempotencyService::class),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    private function stubAccountLookups(Account ...$accounts): AccountRepository
    {
        $repo = $this->createStub(AccountRepository::class);
        $byId = [];
        foreach ($accounts as $account) {
            $byId[$account->getId()] = $account;
        }
        $repo->method('lockForUpdate')
            ->willReturnCallback(fn (int $id) => $byId[$id] ?? null);

        return $repo;
    }

    private function makeAccountWithId(int $id, string $name, int $balance): Account
    {
        $account = new Account($name, $balance);
        $ref = new \ReflectionProperty(Account::class, 'id');
        $ref->setValue($account, $id);

        return $account;
    }

    // --- Happy path ---

    public function testCreateTransferHappyPath(): void
    {
        $source = $this->makeAccountWithId(1, 'Alice', 10000);
        $dest = $this->makeAccountWithId(2, 'Bob', 5000);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('beginTransaction');
        $em->expects($this->atLeastOnce())->method('persist');
        $em->expects($this->once())->method('flush');
        $em->expects($this->once())->method('commit');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ProcessTransferMessage::class))
            ->willReturnCallback(fn ($msg) => new Envelope($msg));

        $service = $this->makeService(
            em: $em,
            accountRepository: $this->stubAccountLookups($source, $dest),
            bus: $bus,
        );

        $transfer = $service->createTransfer(1, 2, 3000);

        $this->assertSame(TransferStatus::Reserved, $transfer->getStatus());
        $this->assertSame(3000, $transfer->getAmount());
        $this->assertSame(7000, $source->getBalance());
        $this->assertSame(8000, $dest->getBalance());
    }

    // --- Validation guards ---

    public function testCreateTransferThrowsOnSameAccount(): void
    {
        $this->expectException(\App\Exception\SameAccountTransferException::class);

        $this->makeService()->createTransfer(1, 1, 1000);
    }

    public function testCreateTransferThrowsOnZeroAmount(): void
    {
        $this->expectException(\App\Exception\InvalidTransferAmountException::class);

        $this->makeService()->createTransfer(1, 2, 0);
    }

    public function testCreateTransferThrowsOnNegativeAmount(): void
    {
        $this->expectException(\App\Exception\InvalidTransferAmountException::class);

        $this->makeService()->createTransfer(1, 2, -500);
    }

    // --- Idempotency: fast path ---

    public function testIdempotencyKeyHitReturnsExistingTransfer(): void
    {
        $existingTransfer = new Transfer(
            $this->makeAccountWithId(1, 'A', 1000),
            $this->makeAccountWithId(2, 'B', 1000),
            500,
        );

        $idempotency = $this->createMock(IdempotencyService::class);
        $idempotency->method('check')->with('existing-key')->willReturn('some-transfer-id');
        $idempotency->expects($this->never())->method('reserve');

        $transferRepository = $this->createStub(TransferRepository::class);
        $transferRepository->method('find')->willReturn($existingTransfer);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('beginTransaction');

        $service = $this->makeService(
            em: $em,
            transferRepository: $transferRepository,
            idempotency: $idempotency,
        );

        $this->assertSame(
            $existingTransfer,
            $service->createTransfer(1, 2, 500, null, 'existing-key'),
        );
    }

    public function testIdempotencyKeyHitButTransferDeletedFallsThroughToReserve(): void
    {
        $idempotency = $this->createMock(IdempotencyService::class);
        $idempotency->method('check')->willReturn('deleted-transfer-id');
        $idempotency->expects($this->once())->method('reserve')->willReturn(true);

        $transferRepository = $this->createStub(TransferRepository::class);
        $transferRepository->method('find')->willReturn(null);

        $source = $this->makeAccountWithId(1, 'Alice', 10000);
        $dest = $this->makeAccountWithId(2, 'Bob', 5000);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('beginTransaction');
        $em->expects($this->once())->method('commit');

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(fn ($msg) => new Envelope($msg));

        $service = $this->makeService(
            em: $em,
            transferRepository: $transferRepository,
            accountRepository: $this->stubAccountLookups($source, $dest),
            bus: $bus,
            idempotency: $idempotency,
        );

        $result = $service->createTransfer(1, 2, 500, null, 'orphan-key');

        $this->assertSame(TransferStatus::Reserved, $result->getStatus());
    }

    public function testNullIdempotencyKeySkipsCheckAndReserve(): void
    {
        $source = $this->makeAccountWithId(1, 'Alice', 10000);
        $dest = $this->makeAccountWithId(2, 'Bob', 5000);

        $idempotency = $this->createMock(IdempotencyService::class);
        $idempotency->expects($this->never())->method('check');
        $idempotency->expects($this->never())->method('reserve');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('commit');

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(fn ($msg) => new Envelope($msg));

        $service = $this->makeService(
            em: $em,
            accountRepository: $this->stubAccountLookups($source, $dest),
            bus: $bus,
            idempotency: $idempotency,
        );

        $service->createTransfer(1, 2, 100);
    }

    // --- Idempotency: atomic reservation ---

    public function testIdempotencyReservedBeforeBeginningTransaction(): void
    {
        $source = $this->makeAccountWithId(1, 'Alice', 10000);
        $dest = $this->makeAccountWithId(2, 'Bob', 5000);

        $reserveCalled = false;

        $idempotency = $this->createMock(IdempotencyService::class);
        $idempotency->method('check')->willReturn(null);
        $idempotency->expects($this->once())
            ->method('reserve')
            ->with('my-key', $this->isString())
            ->willReturnCallback(function () use (&$reserveCalled) {
                $reserveCalled = true;

                return true;
            });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('beginTransaction')
            ->willReturnCallback(function () use (&$reserveCalled) {
                $this->assertTrue($reserveCalled, 'reserve() must be called before beginTransaction()');
            });
        $em->method('commit');

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(fn ($msg) => new Envelope($msg));

        $service = $this->makeService(
            em: $em,
            accountRepository: $this->stubAccountLookups($source, $dest),
            bus: $bus,
            idempotency: $idempotency,
        );

        $service->createTransfer(1, 2, 500, null, 'my-key');
    }

    public function testReservationConflictReturnsWinningTransfer(): void
    {
        $winner = new Transfer(
            $this->makeAccountWithId(1, 'A', 1000),
            $this->makeAccountWithId(2, 'B', 1000),
            500,
        );

        $idempotency = $this->createStub(IdempotencyService::class);
        $idempotency->method('check')->willReturnOnConsecutiveCalls(null, 'winner-id');
        $idempotency->method('reserve')->willReturn(false);

        $transferRepository = $this->createStub(TransferRepository::class);
        $transferRepository->method('find')->willReturn($winner);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('beginTransaction');

        $service = $this->makeService(
            em: $em,
            transferRepository: $transferRepository,
            idempotency: $idempotency,
        );

        $result = $service->createTransfer(1, 2, 500, null, 'raced-key');

        $this->assertSame($winner, $result);
    }

    public function testReservationConflictWithNoWinnerThrows(): void
    {
        $idempotency = $this->createStub(IdempotencyService::class);
        $idempotency->method('check')->willReturn(null);
        $idempotency->method('reserve')->willReturn(false);

        $transferRepository = $this->createStub(TransferRepository::class);
        $transferRepository->method('find')->willReturn(null);

        $this->expectException(\App\Exception\IdempotencyConflictException::class);

        $this->makeService(
            transferRepository: $transferRepository,
            idempotency: $idempotency,
        )->createTransfer(1, 2, 500, null, 'in-flight-key');
    }

    public function testReleaseCalledOnTransactionFailure(): void
    {
        $source = $this->makeAccountWithId(1, 'Alice', 10000);
        $dest = $this->makeAccountWithId(2, 'Bob', 5000);

        $idempotency = $this->createMock(IdempotencyService::class);
        $idempotency->method('check')->willReturn(null);
        $idempotency->method('reserve')->willReturn(true);
        $idempotency->expects($this->once())->method('release')->with('my-key');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new \RuntimeException('boom'));
        $em->expects($this->once())->method('rollback');

        $service = $this->makeService(
            em: $em,
            accountRepository: $this->stubAccountLookups($source, $dest),
            idempotency: $idempotency,
        );

        $this->expectException(\RuntimeException::class);
        $service->createTransfer(1, 2, 500, null, 'my-key');
    }

    public function testReleaseNotCalledWhenNoIdempotencyKey(): void
    {
        $source = $this->makeAccountWithId(1, 'Alice', 10000);
        $dest = $this->makeAccountWithId(2, 'Bob', 5000);

        $idempotency = $this->createMock(IdempotencyService::class);
        $idempotency->expects($this->never())->method('release');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new \RuntimeException('boom'));
        $em->expects($this->once())->method('rollback');

        $service = $this->makeService(
            em: $em,
            accountRepository: $this->stubAccountLookups($source, $dest),
            idempotency: $idempotency,
        );

        $this->expectException(\RuntimeException::class);
        $service->createTransfer(1, 2, 500);
    }

    // --- Account not found ---

    public function testSourceAccountNotFoundThrows(): void
    {
        $accountRepository = $this->createStub(AccountRepository::class);
        $accountRepository->method('lockForUpdate')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('beginTransaction');
        $em->expects($this->once())->method('rollback');

        $service = $this->makeService(
            em: $em,
            accountRepository: $accountRepository,
        );

        $this->expectException(\App\Exception\AccountNotFoundException::class);

        $service->createTransfer(999, 2, 100);
    }

    // --- Insufficient funds ---

    public function testInsufficientFundsRollsBack(): void
    {
        $source = $this->makeAccountWithId(1, 'Alice', 100);
        $dest = $this->makeAccountWithId(2, 'Bob', 5000);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('beginTransaction');
        $em->expects($this->once())->method('rollback');
        $em->expects($this->never())->method('commit');

        $service = $this->makeService(
            em: $em,
            accountRepository: $this->stubAccountLookups($source, $dest),
        );

        $this->expectException(\App\Exception\InsufficientFundsException::class);
        $this->expectExceptionMessage('Insufficient funds.');

        $service->createTransfer(1, 2, 500);
    }

    // --- Lock order ---

    public function testAccountsLockedInAscendingIdOrder(): void
    {
        $source = $this->makeAccountWithId(5, 'Alice', 10000);
        $dest = $this->makeAccountWithId(2, 'Bob', 5000);

        $lockOrder = [];
        $accountRepository = $this->createStub(AccountRepository::class);
        $accountRepository->method('lockForUpdate')
            ->willReturnCallback(function (int $id) use ($source, $dest, &$lockOrder) {
                $lockOrder[] = $id;

                return match ($id) {
                    5 => $source,
                    2 => $dest,
                    default => null,
                };
            });

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('commit');

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(fn ($msg) => new Envelope($msg));

        $this->makeService(
            em: $em,
            accountRepository: $accountRepository,
            bus: $bus,
        )->createTransfer(5, 2, 100);

        $this->assertSame([2, 5], $lockOrder);
    }

    // --- Messenger dispatch ---

    public function testProcessTransferMessageDispatched(): void
    {
        $source = $this->makeAccountWithId(1, 'Alice', 10000);
        $dest = $this->makeAccountWithId(2, 'Bob', 5000);

        $dispatched = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($msg) use (&$dispatched) {
                $dispatched = $msg;

                return new Envelope($msg);
            });

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('commit');

        $service = $this->makeService(
            em: $em,
            accountRepository: $this->stubAccountLookups($source, $dest),
            bus: $bus,
        );

        $transfer = $service->createTransfer(1, 2, 500);

        $this->assertInstanceOf(ProcessTransferMessage::class, $dispatched);
        $this->assertSame($transfer->getId(), $dispatched->getTransferId());
    }

    // --- Read methods ---

    public function testGetTransferReturnsTransferWhenFound(): void
    {
        $transfer = new Transfer(
            $this->makeAccountWithId(1, 'A', 1000),
            $this->makeAccountWithId(2, 'B', 1000),
            500,
        );

        $transferRepository = $this->createStub(TransferRepository::class);
        $transferRepository->method('find')->willReturn($transfer);

        $this->assertSame(
            $transfer,
            $this->makeService(transferRepository: $transferRepository)->getTransfer('uuid-123'),
        );
    }

    public function testGetTransferReturnsNullWhenNotFound(): void
    {
        $transferRepository = $this->createStub(TransferRepository::class);
        $transferRepository->method('find')->willReturn(null);

        $this->assertNull(
            $this->makeService(transferRepository: $transferRepository)->getTransfer('nonexistent'),
        );
    }

    public function testGetAccountReturnsAccountWhenFound(): void
    {
        $account = $this->makeAccountWithId(1, 'Alice', 5000);

        $accountRepository = $this->createStub(AccountRepository::class);
        $accountRepository->method('find')->willReturn($account);

        $this->assertSame(
            $account,
            $this->makeService(accountRepository: $accountRepository)->getAccount(1),
        );
    }

    public function testGetAccountReturnsNullWhenNotFound(): void
    {
        $accountRepository = $this->createStub(AccountRepository::class);
        $accountRepository->method('find')->willReturn(null);

        $this->assertNull(
            $this->makeService(accountRepository: $accountRepository)->getAccount(999),
        );
    }
}
