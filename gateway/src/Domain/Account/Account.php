<?php

declare(strict_types=1);

namespace App\Domain\Account;

use App\Exception\InsufficientFundsException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'accounts')]
class Account
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $holderName;

    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    private string $currency = 'EUR';

    /** Balance stored in cents to avoid floating-point issues */
    #[ORM\Column(type: Types::BIGINT)]
    private int $balance = 0;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $holderName, int $balanceCents = 0, string $currency = 'EUR')
    {
        $this->holderName = $holderName;
        $this->balance = $balanceCents;
        $this->currency = $currency;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHolderName(): string
    {
        return $this->holderName;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getBalance(): int
    {
        return $this->balance;
    }

    public function debit(int $amountCents): void
    {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Debit amount must be positive.');
        }
        if ($this->balance < $amountCents) {
            throw new InsufficientFundsException();
        }
        $this->balance -= $amountCents;
    }

    public function credit(int $amountCents): void
    {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive.');
        }
        $this->balance += $amountCents;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
