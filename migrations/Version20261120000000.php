<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the character behind each PVE income and expense';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pve_income ADD character_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE pve_expenses ADD character_id BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pve_income DROP COLUMN character_id');
        $this->addSql('ALTER TABLE pve_expenses DROP COLUMN character_id');
    }
}
