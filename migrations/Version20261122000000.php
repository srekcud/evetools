<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the signals that decided each automatic job match';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_job_matches ADD match_signals JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_job_matches DROP COLUMN match_signals');
    }
}
