<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Domain\Account\Account;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AccountFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $alice = new Account('Alice', 10000, 'EUR');
        $bob = new Account('Bob', 10000, 'EUR');

        $manager->persist($alice);
        $manager->persist($bob);
        $manager->flush();
    }
}
