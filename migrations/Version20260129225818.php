<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260129225818 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_project_steps ADD time_per_run INT DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD split_group_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD split_index INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE industry_project_steps ADD total_group_runs INT DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_projects ADD max_job_duration_days DOUBLE PRECISION NOT NULL DEFAULT 2.0');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_project_steps DROP time_per_run');
        $this->addSql('ALTER TABLE industry_project_steps DROP split_group_id');
        $this->addSql('ALTER TABLE industry_project_steps DROP split_index');
        $this->addSql('ALTER TABLE industry_project_steps DROP total_group_runs');
        $this->addSql('ALTER TABLE industry_projects DROP max_job_duration_days');
    }
}
