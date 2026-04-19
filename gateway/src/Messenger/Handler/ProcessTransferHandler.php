<?php

declare(strict_types=1);

namespace App\Messenger\Handler;

use App\Domain\Transfer\TransferRepository;
use App\Infrastructure\Provider\ProviderClientInterface;
use App\Messenger\Message\DispatchWebhookMessage;
use App\Messenger\Message\ProcessTransferMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class ProcessTransferHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private TransferRepository $transferRepository,
        private ProviderClientInterface $provider,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ProcessTransferMessage $message): void
    {
        $transfer = $this->transferRepository->find($message->getTransferId());

        if ($transfer === null) {
            // Poison pill — log and swallow so Messenger doesn't retry forever.
            $this->logger->error('Transfer not found for processing', ['id' => $message->getTransferId()]);

            return;
        }

        // Idempotency: Messenger is at-least-once. If the transfer has already reached a
        // terminal state, a prior invocation succeeded; re-processing would double-charge
        // the provider and/or fire a duplicate webhook.
        if ($transfer->isInTerminalStatus()) {
            $this->logger->info('Skipping already-processed transfer', [
                'id' => $transfer->getId(),
                'status' => $transfer->getStatus()->value,
            ]);

            return;
        }

        $transfer->markProcessing();
        $this->em->flush();

        try {
            $result = $this->provider->processTransfer($transfer);

            if ($result['status'] === 'completed') {
                $transfer->markDone();
                $this->logger->info('Transfer completed via provider', ['id' => $transfer->getId()]);
            } else {
                $transfer->markFailed($result['reason'] ?? 'Provider rejected the transfer.');
                $this->logger->warning('Transfer failed via provider', [
                    'id' => $transfer->getId(),
                    'reason' => $result['reason'] ?? 'unknown',
                ]);
            }

            $this->em->flush();

            if ($transfer->getCallbackUrl() !== null) {
                $this->bus->dispatch(new DispatchWebhookMessage($transfer->getId()));
            }
        } catch (\Throwable $e) {
            $transfer->markFailed('Provider communication error: ' . $e->getMessage());
            $this->em->flush();
            $this->logger->error('Provider call failed', [
                'id' => $transfer->getId(),
                'exception' => $e->getMessage(),
            ]);

            if ($transfer->getCallbackUrl() !== null) {
                $this->bus->dispatch(new DispatchWebhookMessage($transfer->getId()));
            }

            throw $e; // trigger Messenger retry
        }
    }
}
