<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the activity feed of an industry project and keep the start date of the job links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE industry_project_activities (id UUID NOT NULL, kind VARCHAR(40) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, data JSON NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_DDEB0F92166D1F9C87C03D1B ON industry_project_activities (project_id, occurred_at)');
        $this->addSql('ALTER TABLE industry_project_activities ADD CONSTRAINT FK_DDEB0F92166D1F9C FOREIGN KEY (project_id) REFERENCES industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE industry_project_job_matches ADD start_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_activities DROP CONSTRAINT FK_DDEB0F92166D1F9C');
        $this->addSql('DROP TABLE industry_project_activities');
        $this->addSql('ALTER TABLE industry_project_job_matches DROP start_date');
    }
}
