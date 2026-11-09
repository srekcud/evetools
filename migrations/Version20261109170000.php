<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give industry projects and project templates a sell structure, defaulting existing projects to the preferred market structure of their owner';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_projects ADD sell_structure_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_projects ADD sell_structure_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_templates ADD sell_structure_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_templates ADD sell_structure_name VARCHAR(255) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE industry_projects
            SET sell_structure_id = users.preferred_market_structure_id,
                sell_structure_name = COALESCE(users.preferred_market_structure_name, 'Structure ' || users.preferred_market_structure_id)
            FROM users
            WHERE users.id = industry_projects.owner_id
              AND users.preferred_market_structure_id IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The sell structures of the projects cannot be recreated once dropped.');
    }
}
