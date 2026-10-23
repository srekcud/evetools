<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the runs entered on an industry project step across recalculations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_steps ADD runs_fixed BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_steps DROP runs_fixed');
    }
}
