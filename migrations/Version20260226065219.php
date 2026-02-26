<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260226065219 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE group_industry_bom_items (id UUID NOT NULL, type_id INT NOT NULL, type_name VARCHAR(255) NOT NULL, required_quantity INT NOT NULL, fulfilled_quantity INT NOT NULL, estimated_price DOUBLE PRECISION DEFAULT NULL, is_job BOOLEAN NOT NULL, job_group VARCHAR(20) DEFAULT NULL, activity_type VARCHAR(20) DEFAULT NULL, parent_type_id INT DEFAULT NULL, me_level INT DEFAULT NULL, te_level INT DEFAULT NULL, runs INT DEFAULT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_1F9191D1166D1F9C ON group_industry_bom_items (project_id)');
        $this->addSql('CREATE INDEX IDX_1F9191D1C54C8C93 ON group_industry_bom_items (type_id)');
        $this->addSql('CREATE TABLE group_industry_contributions (id UUID NOT NULL, type VARCHAR(20) NOT NULL, quantity INT NOT NULL, estimated_value DOUBLE PRECISION NOT NULL, status VARCHAR(10) NOT NULL, is_auto_detected BOOLEAN NOT NULL, is_verified BOOLEAN NOT NULL, verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, note TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, member_id UUID NOT NULL, bom_item_id UUID DEFAULT NULL, reviewed_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3318EA2FEDFACB51 ON group_industry_contributions (bom_item_id)');
        $this->addSql('CREATE INDEX IDX_3318EA2FFC6B21F1 ON group_industry_contributions (reviewed_by_id)');
        $this->addSql('CREATE INDEX IDX_3318EA2F166D1F9C ON group_industry_contributions (project_id)');
        $this->addSql('CREATE INDEX IDX_3318EA2F7597D3FE ON group_industry_contributions (member_id)');
        $this->addSql('CREATE INDEX IDX_3318EA2F7B00651C ON group_industry_contributions (status)');
        $this->addSql('CREATE TABLE group_industry_project_items (id UUID NOT NULL, type_id INT NOT NULL, type_name VARCHAR(255) NOT NULL, me_level INT NOT NULL, te_level INT NOT NULL, runs INT NOT NULL, sort_order INT NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B76A1402166D1F9C ON group_industry_project_items (project_id)');
        $this->addSql('CREATE TABLE group_industry_project_members (id UUID NOT NULL, role VARCHAR(10) NOT NULL, status VARCHAR(10) NOT NULL, joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F14D59E0166D1F9C ON group_industry_project_members (project_id)');
        $this->addSql('CREATE INDEX IDX_F14D59E0A76ED395 ON group_industry_project_members (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F14D59E0166D1F9CA76ED395 ON group_industry_project_members (project_id, user_id)');
        $this->addSql('CREATE TABLE group_industry_projects (id UUID NOT NULL, name VARCHAR(255) DEFAULT NULL, status VARCHAR(20) NOT NULL, short_link_code VARCHAR(10) NOT NULL, container_name VARCHAR(255) DEFAULT NULL, line_rental_rates_override JSON DEFAULT NULL, blacklist_group_ids JSON NOT NULL, blacklist_type_ids JSON NOT NULL, broker_fee_percent DOUBLE PRECISION NOT NULL, sales_tax_percent DOUBLE PRECISION NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A28F861DB83B7806 ON group_industry_projects (short_link_code)');
        $this->addSql('CREATE INDEX IDX_A28F861D7E3C61F9 ON group_industry_projects (owner_id)');
        $this->addSql('CREATE INDEX IDX_A28F861D7B00651C ON group_industry_projects (status)');
        $this->addSql('CREATE TABLE group_industry_sales (id UUID NOT NULL, type_id INT NOT NULL, type_name VARCHAR(255) NOT NULL, quantity INT NOT NULL, unit_price DOUBLE PRECISION NOT NULL, total_price DOUBLE PRECISION NOT NULL, venue VARCHAR(255) DEFAULT NULL, sold_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, recorded_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4C462DD8D05A957B ON group_industry_sales (recorded_by_id)');
        $this->addSql('CREATE INDEX IDX_4C462DD8166D1F9C ON group_industry_sales (project_id)');
        $this->addSql('ALTER TABLE group_industry_bom_items ADD CONSTRAINT FK_1F9191D1166D1F9C FOREIGN KEY (project_id) REFERENCES group_industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_industry_contributions ADD CONSTRAINT FK_3318EA2F166D1F9C FOREIGN KEY (project_id) REFERENCES group_industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_industry_contributions ADD CONSTRAINT FK_3318EA2F7597D3FE FOREIGN KEY (member_id) REFERENCES group_industry_project_members (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_industry_contributions ADD CONSTRAINT FK_3318EA2FEDFACB51 FOREIGN KEY (bom_item_id) REFERENCES group_industry_bom_items (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_industry_contributions ADD CONSTRAINT FK_3318EA2FFC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_industry_project_items ADD CONSTRAINT FK_B76A1402166D1F9C FOREIGN KEY (project_id) REFERENCES group_industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_industry_project_members ADD CONSTRAINT FK_F14D59E0166D1F9C FOREIGN KEY (project_id) REFERENCES group_industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_industry_project_members ADD CONSTRAINT FK_F14D59E0A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_industry_projects ADD CONSTRAINT FK_A28F861D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_industry_sales ADD CONSTRAINT FK_4C462DD8166D1F9C FOREIGN KEY (project_id) REFERENCES group_industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_industry_sales ADD CONSTRAINT FK_4C462DD8D05A957B FOREIGN KEY (recorded_by_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE users ADD line_rental_rates JSON DEFAULT \'{}\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE group_industry_bom_items DROP CONSTRAINT FK_1F9191D1166D1F9C');
        $this->addSql('ALTER TABLE group_industry_contributions DROP CONSTRAINT FK_3318EA2F166D1F9C');
        $this->addSql('ALTER TABLE group_industry_contributions DROP CONSTRAINT FK_3318EA2F7597D3FE');
        $this->addSql('ALTER TABLE group_industry_contributions DROP CONSTRAINT FK_3318EA2FEDFACB51');
        $this->addSql('ALTER TABLE group_industry_contributions DROP CONSTRAINT FK_3318EA2FFC6B21F1');
        $this->addSql('ALTER TABLE group_industry_project_items DROP CONSTRAINT FK_B76A1402166D1F9C');
        $this->addSql('ALTER TABLE group_industry_project_members DROP CONSTRAINT FK_F14D59E0166D1F9C');
        $this->addSql('ALTER TABLE group_industry_project_members DROP CONSTRAINT FK_F14D59E0A76ED395');
        $this->addSql('ALTER TABLE group_industry_projects DROP CONSTRAINT FK_A28F861D7E3C61F9');
        $this->addSql('ALTER TABLE group_industry_sales DROP CONSTRAINT FK_4C462DD8166D1F9C');
        $this->addSql('ALTER TABLE group_industry_sales DROP CONSTRAINT FK_4C462DD8D05A957B');
        $this->addSql('DROP TABLE group_industry_bom_items');
        $this->addSql('DROP TABLE group_industry_contributions');
        $this->addSql('DROP TABLE group_industry_project_items');
        $this->addSql('DROP TABLE group_industry_project_members');
        $this->addSql('DROP TABLE group_industry_projects');
        $this->addSql('DROP TABLE group_industry_sales');
        $this->addSql('ALTER TABLE users DROP line_rental_rates');
    }
}
