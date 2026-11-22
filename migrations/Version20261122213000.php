<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the market chosen for each purchased line of the industry project BOM';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_bom_lines ADD sourcing_market VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_bom_lines DROP sourcing_market');
    }
}
