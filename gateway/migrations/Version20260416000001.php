<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create accounts table';
    }

    public function up(Schema $schema): void
    {
        $accounts = $schema->createTable('accounts');
        $accounts->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $accounts->addColumn('holder_name', Types::STRING, ['length' => 255]);
        $accounts->addColumn('currency', Types::STRING, ['length' => 3, 'default' => 'EUR']);
        $accounts->addColumn('balance', Types::BIGINT, ['default' => 0]);
        $accounts->addColumn('version', Types::INTEGER, ['default' => 1]);
        $accounts->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $accounts->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()
                ->addColumnName(UnqualifiedName::unquoted('id'))
                ->create(),
        );
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('accounts');
    }
}
