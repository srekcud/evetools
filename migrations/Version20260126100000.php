<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260126100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add PVE sessions table and related fields';
    }

    public function up(Schema $schema): void
    {
        // Create pve_sessions table
        $this->addSql('CREATE TABLE pve_sessions (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            notes VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_pve_session_user_status ON pve_sessions (user_id, status)');
        $this->addSql('CREATE INDEX idx_pve_session_user_started ON pve_sessions (user_id, started_at)');
        $this->addSql('ALTER TABLE pve_sessions ADD CONSTRAINT FK_pve_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('COMMENT ON COLUMN pve_sessions.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN pve_sessions.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN pve_sessions.started_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN pve_sessions.ended_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN pve_sessions.created_at IS \'(DC2Type:datetime_immutable)\'');

        // Add session_id and journal_entry_id to pve_income
        $this->addSql('ALTER TABLE pve_income ADD session_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE pve_income ADD journal_entry_id BIGINT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_pve_income_session ON pve_income (session_id)');
        $this->addSql('CREATE INDEX IDX_pve_income_journal ON pve_income (user_id, journal_entry_id)');
        $this->addSql('ALTER TABLE pve_income ADD CONSTRAINT FK_pve_income_session FOREIGN KEY (session_id) REFERENCES pve_sessions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('COMMENT ON COLUMN pve_income.session_id IS \'(DC2Type:uuid)\'');

        // Add session_id to pve_expenses
        $this->addSql('ALTER TABLE pve_expenses ADD session_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_pve_expenses_session ON pve_expenses (session_id)');
        $this->addSql('ALTER TABLE pve_expenses ADD CONSTRAINT FK_pve_expenses_session FOREIGN KEY (session_id) REFERENCES pve_sessions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('COMMENT ON COLUMN pve_expenses.session_id IS \'(DC2Type:uuid)\'');

        // Add sync fields to user_pve_settings
        $this->addSql('ALTER TABLE user_pve_settings ADD last_sync_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE user_pve_settings ADD auto_sync_enabled BOOLEAN NOT NULL DEFAULT true');
        $this->addSql('COMMENT ON COLUMN user_pve_settings.last_sync_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // Remove sync fields from user_pve_settings
        $this->addSql('ALTER TABLE user_pve_settings DROP last_sync_at');
        $this->addSql('ALTER TABLE user_pve_settings DROP auto_sync_enabled');

        // Remove session_id from pve_expenses
        $this->addSql('ALTER TABLE pve_expenses DROP CONSTRAINT FK_pve_expenses_session');
        $this->addSql('DROP INDEX IDX_pve_expenses_session');
        $this->addSql('ALTER TABLE pve_expenses DROP session_id');

        // Remove session_id and journal_entry_id from pve_income
        $this->addSql('ALTER TABLE pve_income DROP CONSTRAINT FK_pve_income_session');
        $this->addSql('DROP INDEX IDX_pve_income_session');
        $this->addSql('DROP INDEX IDX_pve_income_journal');
        $this->addSql('ALTER TABLE pve_income DROP session_id');
        $this->addSql('ALTER TABLE pve_income DROP journal_entry_id');

        // Drop pve_sessions table
        $this->addSql('DROP TABLE pve_sessions');
    }
}
