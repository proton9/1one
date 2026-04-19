<?php

declare(strict_types=1);

namespace App\Domain\Account;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * Fetch and pessimistically lock an account (SELECT ... FOR UPDATE).
     * Must be called inside an active transaction.
     */
    public function lockForUpdate(int $id): ?Account
    {
        return $this->getEntityManager()->find(Account::class, $id, LockMode::PESSIMISTIC_WRITE);
    }
}
