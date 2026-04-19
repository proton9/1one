<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Account\Account;
use App\Domain\Account\AccountRepository;
use App\Domain\Ledger\LedgerDirection;
use App\Domain\Ledger\LedgerEntry;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferRepository;
use App\Exception\AccountNotFoundException;
use App\Exception\IdempotencyConflictException;
use App\Exception\InvalidTransferAmountException;
use App\Exception\SameAccountTransferException;
use App\Messenger\Message\ProcessTransferMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

class TransferService
{
    public function __construct(
        private EntityManagerInterface $em,
        private TransferRepository $transferRepository,
        private AccountRepository $accountRepository,
        private MessageBusInterface $bus,
        private IdempotencyService $idempotency,
        private LoggerInterface $logger,
    ) {}

    /**
     * Create a fund transfer between two accounts.
     *
     * Idempotency: reservation via Redis SET NX happens *before* the DB transaction,
     * so concurrent requests with the same key can only produce one transfer.
     *
     * @throws \App\Exception\BusinessException on business-rule failures
     */
    public function createTransfer(
        int $sourceAccountId,
        int $destAccountId,
        int $amountCents,
        ?string $callbackUrl = null,
        ?string $idempotencyKey = null,
    ): Transfer {
        if ($sourceAccountId === $destAccountId) {
            throw new SameAccountTransferException();
        }

        if ($amountCents <= 0) {
            throw new InvalidTransferAmountException();
        }

        if ($idempotencyKey !== null) {
            $existing = $this->lookupExistingTransfer($idempotencyKey);
            if ($existing !== null) {
                $this->logger->info('Idempotent request: returning existing transfer', [
                    'transfer_id' => $existing->getId(),
                ]);

                return $existing;
            }
        }

        $transferId = Uuid::v4()->toRfc4122();

        if ($idempotencyKey !== null && !$this->idempotency->reserve($idempotencyKey, $transferId)) {
            $existing = $this->lookupExistingTransfer($idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }

            throw new IdempotencyConflictException();
        }

        $firstId = min($sourceAccountId, $destAccountId);
        $secondId = max($sourceAccountId, $destAccountId);

        $this->em->beginTransaction();
        try {
            $first = $this->accountRepository->lockForUpdate($firstId);
            $second = $this->accountRepository->lockForUpdate($secondId);

            if ($first === null || $second === null) {
                throw new AccountNotFoundException();
            }

            $source = $first->getId() === $sourceAccountId ? $first : $second;
            $dest = $first->getId() === $destAccountId ? $first : $second;

            $source->debit($amountCents);
            $dest->credit($amountCents);

            $transfer = new Transfer($source, $dest, $amountCents, $callbackUrl, $idempotencyKey, $transferId);
            $debitEntry = new LedgerEntry($transfer, $source, LedgerDirection::Debit, $amountCents);
            $creditEntry = new LedgerEntry($transfer, $dest, LedgerDirection::Credit, $amountCents);

            $this->em->persist($transfer);
            $this->em->persist($debitEntry);
            $this->em->persist($creditEntry);
            $this->em->flush();
            $this->em->commit();

            $this->logger->info('Transfer created', [
                'transfer_id' => $transfer->getId(),
                'source' => $sourceAccountId,
                'dest' => $destAccountId,
                'amount_cents' => $amountCents,
            ]);

            $this->bus->dispatch(new ProcessTransferMessage($transfer->getId()));

            return $transfer;
        } catch (\Throwable $e) {
            $this->em->rollback();
            if ($idempotencyKey !== null) {
                $this->idempotency->release($idempotencyKey);
            }
            throw $e;
        }
    }

    public function getTransfer(string $id): ?Transfer
    {
        return $this->transferRepository->find($id);
    }

    public function getAccount(int $id): ?Account
    {
        return $this->accountRepository->find($id);
    }

    private function lookupExistingTransfer(string $idempotencyKey): ?Transfer
    {
        $existingId = $this->idempotency->check($idempotencyKey);
        if ($existingId === null) {
            return null;
        }

        return $this->transferRepository->find($existingId);
    }
}
