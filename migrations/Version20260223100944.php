<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260223100944 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove dead code: drop pve_sessions table and session_id columns from pve_income/pve_expenses, drop orphan profit_matches/profit_settings tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE profit_matches DROP CONSTRAINT fk_942a3a82a76ed395');
        $this->addSql('ALTER TABLE profit_matches DROP CONSTRAINT fk_942a3a82be04ea9');
        $this->addSql('ALTER TABLE profit_matches DROP CONSTRAINT fk_942a3a822fc0cb0f');
        $this->addSql('ALTER TABLE profit_settings DROP CONSTRAINT fk_cdd51474a76ed395');
        $this->addSql('ALTER TABLE pve_expenses DROP CONSTRAINT fk_pve_expenses_session');
        $this->addSql('DROP INDEX idx_9299b28a613fecdf');
        $this->addSql('ALTER TABLE pve_expenses DROP session_id');
        $this->addSql('ALTER TABLE pve_income DROP CONSTRAINT fk_pve_income_session');
        $this->addSql('DROP INDEX idx_5586a343613fecdf');
        $this->addSql('ALTER TABLE pve_income DROP session_id');
        $this->addSql('ALTER TABLE pve_sessions DROP CONSTRAINT fk_pve_sessions_user');
        $this->addSql('DROP TABLE profit_matches');
        $this->addSql('DROP TABLE profit_settings');
        $this->addSql('DROP TABLE pve_sessions');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE profit_matches (id UUID NOT NULL, product_type_id INT NOT NULL, job_runs INT NOT NULL, quantity_sold INT NOT NULL, material_cost DOUBLE PRECISION NOT NULL, job_install_cost DOUBLE PRECISION NOT NULL, tax_amount DOUBLE PRECISION NOT NULL, revenue DOUBLE PRECISION NOT NULL, profit DOUBLE PRECISION NOT NULL, cost_source VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, matched_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, job_id UUID DEFAULT NULL, transaction_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_942a3a822fc0cb0f ON profit_matches (transaction_id)');
        $this->addSql('CREATE INDEX idx_942a3a82a76ed39553902853 ON profit_matches (user_id, matched_at)');
        $this->addSql('CREATE INDEX idx_942a3a82a76ed39514959723 ON profit_matches (user_id, product_type_id)');
        $this->addSql('CREATE INDEX idx_942a3a82a76ed395 ON profit_matches (user_id)');
        $this->addSql('CREATE INDEX idx_942a3a82be04ea9 ON profit_matches (job_id)');
        $this->addSql('CREATE TABLE profit_settings (id UUID NOT NULL, sales_tax_rate DOUBLE PRECISION NOT NULL, default_cost_source VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_cdd51474a76ed395 ON profit_settings (user_id)');
        $this->addSql('CREATE TABLE pve_sessions (id UUID NOT NULL, user_id UUID NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, status VARCHAR(20) NOT NULL, notes VARCHAR(500) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_pve_session_user_status ON pve_sessions (user_id, status)');
        $this->addSql('CREATE INDEX idx_pve_session_user_started ON pve_sessions (user_id, started_at)');
        $this->addSql('CREATE INDEX IDX_2C6FDCC2A76ED395 ON pve_sessions (user_id)');
        $this->addSql('ALTER TABLE profit_matches ADD CONSTRAINT fk_942a3a82a76ed395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE profit_matches ADD CONSTRAINT fk_942a3a82be04ea9 FOREIGN KEY (job_id) REFERENCES cached_industry_jobs (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE profit_matches ADD CONSTRAINT fk_942a3a822fc0cb0f FOREIGN KEY (transaction_id) REFERENCES cached_wallet_transactions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE profit_settings ADD CONSTRAINT fk_cdd51474a76ed395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE pve_sessions ADD CONSTRAINT fk_pve_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE pve_expenses ADD session_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE pve_expenses ADD CONSTRAINT fk_pve_expenses_session FOREIGN KEY (session_id) REFERENCES pve_sessions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_9299b28a613fecdf ON pve_expenses (session_id)');
        $this->addSql('ALTER TABLE pve_income ADD session_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE pve_income ADD CONSTRAINT fk_pve_income_session FOREIGN KEY (session_id) REFERENCES pve_sessions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_5586a343613fecdf ON pve_income (session_id)');
    }
}
