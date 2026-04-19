<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add webhook_delivered_at to transfers for handler idempotency';
    }

    public function up(Schema $schema): void
    {
        $transfers = $schema->getTable('transfers');
        $transfers->addColumn('webhook_delivered_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $transfers = $schema->getTable('transfers');
        $transfers->dropColumn('webhook_delivered_at');
    }
}
