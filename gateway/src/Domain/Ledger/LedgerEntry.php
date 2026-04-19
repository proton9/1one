<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Account\Account;
use App\Domain\Transfer\Transfer;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ledger_entries')]
class LedgerEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Transfer::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Transfer $transfer;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Account $account;

    #[ORM\Column(length: 6, enumType: LedgerDirection::class)]
    private LedgerDirection $direction;

    /** Amount in cents */
    #[ORM\Column(type: Types::BIGINT)]
    private int $amount;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Transfer $transfer, Account $account, LedgerDirection $direction, int $amount)
    {
        $this->transfer = $transfer;
        $this->account = $account;
        $this->direction = $direction;
        $this->amount = $amount;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTransfer(): Transfer
    {
        return $this->transfer;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getDirection(): LedgerDirection
    {
        return $this->direction;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
