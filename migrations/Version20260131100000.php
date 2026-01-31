<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename red_loot type to loot_contract in pve_income table
 */
final class Version20260131100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename red_loot type to loot_contract in pve_income table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE pve_income SET type = 'loot_contract' WHERE type = 'red_loot'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE pve_income SET type = 'red_loot' WHERE type = 'loot_contract'");
    }
}
