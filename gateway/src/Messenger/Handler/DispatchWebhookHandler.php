<?php

declare(strict_types=1);

namespace App\Messenger\Handler;

use App\Domain\Transfer\TransferRepository;
use App\Infrastructure\Webhook\WebhookDispatcher;
use App\Messenger\Message\DispatchWebhookMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class DispatchWebhookHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private TransferRepository $transferRepository,
        private WebhookDispatcher $webhookDispatcher,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(DispatchWebhookMessage $message): void
    {
        $transfer = $this->transferRepository->find($message->getTransferId());

        if ($transfer === null) {
            $this->logger->error('Transfer not found for webhook', ['id' => $message->getTransferId()]);

            return;
        }

        if ($transfer->getCallbackUrl() === null) {
            return;
        }

        // Idempotency: don't re-deliver if this has already been delivered successfully.
        if ($transfer->getWebhookDeliveredAt() !== null) {
            $this->logger->info('Skipping already-delivered webhook', [
                'transfer_id' => $transfer->getId(),
            ]);

            return;
        }

        try {
            $this->webhookDispatcher->dispatch($transfer);
            $transfer->markWebhookDelivered();
            $this->em->flush();

            $this->logger->info('Webhook delivered', [
                'transfer_id' => $transfer->getId(),
                'url' => $transfer->getCallbackUrl(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Webhook delivery failed, will retry', [
                'transfer_id' => $transfer->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e; // Messenger retry with exponential backoff
        }
    }
}
