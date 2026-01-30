<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260128124457 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_project_steps ADD esi_job_ids JSON NOT NULL DEFAULT \'[]\'');
        $this->addSql('ALTER TABLE industry_project_steps ADD esi_jobs_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD esi_jobs_total_runs INT DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD esi_jobs_active_runs INT DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD esi_jobs_delivered_runs INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_project_steps DROP esi_job_ids');
        $this->addSql('ALTER TABLE industry_project_steps DROP esi_jobs_count');
        $this->addSql('ALTER TABLE industry_project_steps DROP esi_jobs_total_runs');
        $this->addSql('ALTER TABLE industry_project_steps DROP esi_jobs_active_runs');
        $this->addSql('ALTER TABLE industry_project_steps DROP esi_jobs_delivered_runs');
    }
}
