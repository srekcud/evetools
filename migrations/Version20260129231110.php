<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260129231110 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_project_steps ADD structure_time_bonus DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ALTER split_index DROP DEFAULT');
        $this->addSql('ALTER TABLE industry_projects ALTER max_job_duration_days DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_project_steps DROP structure_time_bonus');
        $this->addSql('ALTER TABLE industry_project_steps ALTER split_index SET DEFAULT 0');
        $this->addSql('ALTER TABLE industry_projects ALTER max_job_duration_days SET DEFAULT \'2.0\'');
    }
}
