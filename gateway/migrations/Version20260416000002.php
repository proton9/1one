<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create transfers table with FKs to accounts';
    }

    public function up(Schema $schema): void
    {
        $transfers = $schema->createTable('transfers');
        $transfers->addColumn('id', Types::GUID);
        $transfers->addColumn('source_account_id', Types::INTEGER);
        $transfers->addColumn('dest_account_id', Types::INTEGER);
        $transfers->addColumn('amount', Types::BIGINT);
        $transfers->addColumn('currency', Types::STRING, ['length' => 3]);
        $transfers->addColumn('status', Types::STRING, ['length' => 20]);
        $transfers->addColumn('callback_url', Types::STRING, ['length' => 2048, 'notnull' => false]);
        $transfers->addColumn('idempotency_key', Types::STRING, ['length' => 255, 'notnull' => false]);
        $transfers->addColumn('failure_reason', Types::STRING, ['length' => 255, 'notnull' => false]);
        $transfers->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $transfers->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $transfers->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()
                ->addColumnName(UnqualifiedName::unquoted('id'))
                ->create(),
        );
        $transfers->addIndex(['source_account_id'], 'idx_transfer_source');
        $transfers->addIndex(['dest_account_id'], 'idx_transfer_dest');
        $transfers->addIndex(['idempotency_key'], 'idx_idempotency_key');
        $transfers->addForeignKeyConstraint('accounts', ['source_account_id'], ['id']);
        $transfers->addForeignKeyConstraint('accounts', ['dest_account_id'], ['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('transfers');
    }
}
