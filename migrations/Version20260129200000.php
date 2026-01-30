<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add recommended structure columns to industry_project_steps
 */
final class Version20260129200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recommended structure name and bonus to industry project steps';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_steps ADD recommended_structure_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD structure_bonus DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN recommended_structure_name');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN structure_bonus');
    }
}
