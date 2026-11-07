<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the surplus of each intermediate planned for an industry project on its BOM job lines';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_bom_lines ADD surplus_quantity INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The surplus of the planned intermediates cannot be recomputed once dropped.');
    }
}
