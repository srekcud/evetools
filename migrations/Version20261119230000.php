<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remember when the loot sales scan and the expenses scan last ran';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_ledger_settings ADD last_loot_scan_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE user_ledger_settings ADD last_expense_scan_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_ledger_settings DROP COLUMN last_loot_scan_at');
        $this->addSql('ALTER TABLE user_ledger_settings DROP COLUMN last_expense_scan_at');
    }
}
