<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

use App\Domain\Account\Account;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TransferRepository::class)]
#[ORM\Table(name: 'transfers')]
#[ORM\Index(columns: ['idempotency_key'], name: 'idx_idempotency_key')]
class Transfer
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Account $sourceAccount;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Account $destAccount;

    /** Amount in cents */
    #[ORM\Column(type: Types::BIGINT)]
    private int $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 20, enumType: TransferStatus::class)]
    private TransferStatus $status;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $callbackUrl;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $idempotencyKey;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $webhookDeliveredAt = null;

    public function __construct(
        Account $sourceAccount,
        Account $destAccount,
        int $amount,
        ?string $callbackUrl = null,
        ?string $idempotencyKey = null,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::v4()->toRfc4122();
        $this->sourceAccount = $sourceAccount;
        $this->destAccount = $destAccount;
        $this->amount = $amount;
        $this->currency = $sourceAccount->getCurrency();
        $this->status = TransferStatus::Reserved;
        $this->callbackUrl = $callbackUrl;
        $this->idempotencyKey = $idempotencyKey;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSourceAccount(): Account
    {
        return $this->sourceAccount;
    }

    public function getDestAccount(): Account
    {
        return $this->destAccount;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): TransferStatus
    {
        return $this->status;
    }

    public function getCallbackUrl(): ?string
    {
        return $this->callbackUrl;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function markProcessing(): void
    {
        $this->status = TransferStatus::Processing;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markDone(): void
    {
        $this->status = TransferStatus::Done;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $reason): void
    {
        $this->status = TransferStatus::Failed;
        $this->failureReason = $reason;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isInTerminalStatus(): bool
    {
        return $this->status === TransferStatus::Done || $this->status === TransferStatus::Failed;
    }

    public function getWebhookDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->webhookDeliveredAt;
    }

    public function markWebhookDelivered(): void
    {
        $this->webhookDeliveredAt = new \DateTimeImmutable();
    }
}
