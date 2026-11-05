<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete every solo and group industry project with its dependent rows, then merge group_industry_projects into industry_projects';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM group_industry_contributions');
        $this->addSql('DELETE FROM group_industry_sales');
        $this->addSql('DELETE FROM group_industry_bom_items');
        $this->addSql('DELETE FROM group_industry_project_items');
        $this->addSql('DELETE FROM group_industry_project_members');
        $this->addSql('DELETE FROM group_industry_projects');
        $this->addSql('DELETE FROM industry_step_job_matches');
        $this->addSql('DELETE FROM industry_step_purchases');
        $this->addSql('DELETE FROM industry_project_steps');
        $this->addSql('DELETE FROM industry_projects');

        $this->addSql('ALTER TABLE group_industry_bom_items DROP CONSTRAINT fk_1f9191d1166d1f9c');
        $this->addSql('ALTER TABLE group_industry_contributions DROP CONSTRAINT fk_3318ea2f166d1f9c');
        $this->addSql('ALTER TABLE group_industry_project_items DROP CONSTRAINT fk_b76a1402166d1f9c');
        $this->addSql('ALTER TABLE group_industry_project_members DROP CONSTRAINT fk_f14d59e0166d1f9c');
        $this->addSql('ALTER TABLE group_industry_sales DROP CONSTRAINT fk_4c462dd8166d1f9c');
        $this->addSql('DROP TABLE group_industry_projects');

        $this->addSql('ALTER TABLE industry_projects DROP CONSTRAINT fk_7ccba921a76ed395');
        $this->addSql('DROP INDEX idx_7ccba921a76ed395');
        $this->addSql('ALTER TABLE industry_projects RENAME COLUMN user_id TO owner_id');
        $this->addSql('ALTER TABLE industry_projects ADD short_link_code VARCHAR(10) NOT NULL');
        $this->addSql('ALTER TABLE industry_projects ADD container_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_projects ADD line_rental_rates_override JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_projects ADD blacklist_group_ids JSON NOT NULL');
        $this->addSql('ALTER TABLE industry_projects ADD blacklist_type_ids JSON NOT NULL');
        $this->addSql('ALTER TABLE industry_projects ADD broker_fee_percent DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_projects ADD sales_tax_percent DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_projects ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_projects ADD CONSTRAINT FK_7CCBA9217E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7CCBA921B83B7806 ON industry_projects (short_link_code)');
        $this->addSql('CREATE INDEX IDX_7CCBA9217E3C61F9 ON industry_projects (owner_id)');

        $this->addSql('ALTER TABLE group_industry_bom_items ADD CONSTRAINT FK_1F9191D1166D1F9C FOREIGN KEY (project_id) REFERENCES industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_industry_contributions ADD CONSTRAINT FK_3318EA2F166D1F9C FOREIGN KEY (project_id) REFERENCES industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_industry_project_items ADD CONSTRAINT FK_B76A1402166D1F9C FOREIGN KEY (project_id) REFERENCES industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_industry_project_members ADD CONSTRAINT FK_F14D59E0166D1F9C FOREIGN KEY (project_id) REFERENCES industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_industry_sales ADD CONSTRAINT FK_4C462DD8166D1F9C FOREIGN KEY (project_id) REFERENCES industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The deleted industry projects cannot be restored.');
    }
}
