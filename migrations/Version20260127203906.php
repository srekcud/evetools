<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260127203906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cached_industry_jobs (id UUID NOT NULL, job_id INT NOT NULL, activity_id INT NOT NULL, blueprint_type_id INT NOT NULL, product_type_id INT NOT NULL, runs INT NOT NULL, cost DOUBLE PRECISION NOT NULL, status VARCHAR(50) NOT NULL, start_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, end_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, completed_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cached_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, character_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6C79ED921136BE75 ON cached_industry_jobs (character_id)');
        $this->addSql('CREATE INDEX IDX_6C79ED9222AB3297 ON cached_industry_jobs (blueprint_type_id)');
        $this->addSql('CREATE INDEX IDX_6C79ED9214959723 ON cached_industry_jobs (product_type_id)');
        $this->addSql('CREATE INDEX IDX_6C79ED927B00651C ON cached_industry_jobs (status)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6C79ED92BE04EA9 ON cached_industry_jobs (job_id)');
        $this->addSql('CREATE TABLE industry_project_steps (id UUID NOT NULL, blueprint_type_id INT NOT NULL, product_type_id INT NOT NULL, product_type_name VARCHAR(255) NOT NULL, quantity INT NOT NULL, runs INT NOT NULL, depth INT NOT NULL, purchased BOOLEAN NOT NULL, esi_job_id INT DEFAULT NULL, esi_job_cost DOUBLE PRECISION DEFAULT NULL, esi_job_status VARCHAR(50) DEFAULT NULL, esi_job_end_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_96FED05F166D1F9C ON industry_project_steps (project_id)');
        $this->addSql('CREATE INDEX IDX_96FED05F22AB3297 ON industry_project_steps (blueprint_type_id)');
        $this->addSql('CREATE TABLE industry_projects (id UUID NOT NULL, product_type_id INT NOT NULL, product_type_name VARCHAR(255) NOT NULL, runs INT NOT NULL, me_level INT NOT NULL, status VARCHAR(20) NOT NULL, bpo_cost DOUBLE PRECISION DEFAULT NULL, material_cost DOUBLE PRECISION DEFAULT NULL, transport_cost DOUBLE PRECISION DEFAULT NULL, tax_amount DOUBLE PRECISION DEFAULT NULL, sell_price DOUBLE PRECISION DEFAULT NULL, notes TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7CCBA921A76ED395 ON industry_projects (user_id)');
        $this->addSql('CREATE INDEX IDX_7CCBA9217B00651C ON industry_projects (status)');
        $this->addSql('ALTER TABLE cached_industry_jobs ADD CONSTRAINT FK_6C79ED921136BE75 FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE industry_project_steps ADD CONSTRAINT FK_96FED05F166D1F9C FOREIGN KEY (project_id) REFERENCES industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE industry_projects ADD CONSTRAINT FK_7CCBA921A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cached_industry_jobs DROP CONSTRAINT FK_6C79ED921136BE75');
        $this->addSql('ALTER TABLE industry_project_steps DROP CONSTRAINT FK_96FED05F166D1F9C');
        $this->addSql('ALTER TABLE industry_projects DROP CONSTRAINT FK_7CCBA921A76ED395');
        $this->addSql('DROP TABLE cached_industry_jobs');
        $this->addSql('DROP TABLE industry_project_steps');
        $this->addSql('DROP TABLE industry_projects');
    }
}
