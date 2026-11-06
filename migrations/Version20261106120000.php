<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the structure planned for each job of an industry project BOM line and how it was chosen';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_bom_lines ADD planned_structure_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_bom_lines ADD planned_structure_status VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The planned structures of the BOM lines cannot be recomputed once dropped.');
    }
}
