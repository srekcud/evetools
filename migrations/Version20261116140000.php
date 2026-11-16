<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Freeze the Jita launch price of each material line of a project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_bom_lines ADD launch_price DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_bom_lines DROP COLUMN launch_price');
    }
}
