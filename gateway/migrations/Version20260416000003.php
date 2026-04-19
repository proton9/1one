<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ledger_entries table with FKs to transfers and accounts';
    }

    public function up(Schema $schema): void
    {
        $ledger = $schema->createTable('ledger_entries');
        $ledger->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $ledger->addColumn('transfer_id', Types::GUID);
        $ledger->addColumn('account_id', Types::INTEGER);
        $ledger->addColumn('direction', Types::STRING, ['length' => 6]);
        $ledger->addColumn('amount', Types::BIGINT);
        $ledger->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $ledger->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()
                ->addColumnName(UnqualifiedName::unquoted('id'))
                ->create(),
        );
        $ledger->addIndex(['transfer_id'], 'idx_ledger_transfer');
        $ledger->addIndex(['account_id'], 'idx_ledger_account');
        $ledger->addForeignKeyConstraint('transfers', ['transfer_id'], ['id']);
        $ledger->addForeignKeyConstraint('accounts', ['account_id'], ['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('ledger_entries');
    }
}
