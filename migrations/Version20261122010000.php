<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the jobs that no project clearly wins, with their candidate lines';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE industry_unassigned_jobs (id UUID NOT NULL, candidates JSON NOT NULL, detected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, job_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3BE3A34BBE04EA9 ON industry_unassigned_jobs (job_id)');
        $this->addSql('ALTER TABLE industry_unassigned_jobs ADD CONSTRAINT FK_3BE3A34BBE04EA9 FOREIGN KEY (job_id) REFERENCES cached_industry_jobs (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE industry_unassigned_jobs');
    }
}
