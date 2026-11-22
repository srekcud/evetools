<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the successful runs of the ESI industry jobs and of their matches to project lines';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_industry_jobs ADD successful_runs INT DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_job_matches ADD successful_runs INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_job_matches DROP successful_runs');
        $this->addSql('ALTER TABLE cached_industry_jobs DROP successful_runs');
    }
}
