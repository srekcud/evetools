<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260211135848 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cached_industry_jobs ADD station_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_step_job_matches ADD facility_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_step_job_matches ADD facility_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cached_industry_jobs DROP station_id');
        $this->addSql('ALTER TABLE industry_step_job_matches DROP facility_id');
        $this->addSql('ALTER TABLE industry_step_job_matches DROP facility_name');
    }
}
