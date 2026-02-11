<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260209200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'V0.5 Phase 1 - Industry data model refactoring';
    }

    public function up(Schema $schema): void
    {
        // 1. Create industry_step_job_matches table
        $this->addSql('CREATE TABLE industry_step_job_matches (
            id UUID NOT NULL,
            step_id UUID NOT NULL,
            esi_job_id INT NOT NULL,
            cost DOUBLE PRECISION DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            end_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            runs INT NOT NULL,
            character_name VARCHAR(100) NOT NULL,
            matched_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX IDX_STEP_JOB_MATCH_STEP ON industry_step_job_matches (step_id)');
        $this->addSql('CREATE INDEX IDX_STEP_JOB_MATCH_ESI ON industry_step_job_matches (esi_job_id)');
        $this->addSql('ALTER TABLE industry_step_job_matches ADD CONSTRAINT FK_STEP_JOB_MATCH_STEP FOREIGN KEY (step_id) REFERENCES industry_project_steps (id) ON DELETE CASCADE NOT DEFERRABLE');

        // 2. Create cached_wallet_transactions table
        $this->addSql('CREATE TABLE cached_wallet_transactions (
            id UUID NOT NULL,
            character_id UUID NOT NULL,
            transaction_id BIGINT NOT NULL,
            type_id INT NOT NULL,
            quantity INT NOT NULL,
            unit_price DOUBLE PRECISION NOT NULL,
            is_buy BOOLEAN NOT NULL,
            location_id BIGINT NOT NULL,
            client_id BIGINT NOT NULL,
            date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            cached_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX IDX_WALLET_TX_CHAR ON cached_wallet_transactions (character_id)');
        $this->addSql('CREATE INDEX IDX_WALLET_TX_TYPE ON cached_wallet_transactions (type_id)');
        $this->addSql('CREATE INDEX IDX_WALLET_TX_DATE ON cached_wallet_transactions (date)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_WALLET_TX_ID ON cached_wallet_transactions (transaction_id)');
        $this->addSql('ALTER TABLE cached_wallet_transactions ADD CONSTRAINT FK_WALLET_TX_CHAR FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE NOT DEFERRABLE');

        // 3. Create industry_step_purchases table
        $this->addSql('CREATE TABLE industry_step_purchases (
            id UUID NOT NULL,
            step_id UUID NOT NULL,
            transaction_id UUID DEFAULT NULL,
            type_id INT NOT NULL,
            quantity INT NOT NULL,
            unit_price DOUBLE PRECISION NOT NULL,
            total_price DOUBLE PRECISION NOT NULL,
            source VARCHAR(20) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX IDX_STEP_PURCHASE_STEP ON industry_step_purchases (step_id)');
        $this->addSql('CREATE INDEX IDX_STEP_PURCHASE_TX ON industry_step_purchases (transaction_id)');
        $this->addSql('ALTER TABLE industry_step_purchases ADD CONSTRAINT FK_STEP_PURCHASE_STEP FOREIGN KEY (step_id) REFERENCES industry_project_steps (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE industry_step_purchases ADD CONSTRAINT FK_STEP_PURCHASE_TX FOREIGN KEY (transaction_id) REFERENCES cached_wallet_transactions (id) ON DELETE SET NULL NOT DEFERRABLE');

        // 4. Create cached_character_skills table
        $this->addSql('CREATE TABLE cached_character_skills (
            id UUID NOT NULL,
            character_id UUID NOT NULL,
            skill_id INT NOT NULL,
            level INT NOT NULL,
            is_manual BOOLEAN NOT NULL,
            cached_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX IDX_CHAR_SKILL_CHAR ON cached_character_skills (character_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CHAR_SKILL ON cached_character_skills (character_id, skill_id)');
        $this->addSql('ALTER TABLE cached_character_skills ADD CONSTRAINT FK_CHAR_SKILL_CHAR FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE NOT DEFERRABLE');

        // 5. Create industry_user_settings table
        $this->addSql('CREATE TABLE industry_user_settings (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            favorite_manufacturing_system_id INT DEFAULT NULL,
            favorite_reaction_system_id INT DEFAULT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IND_SETTINGS_USER ON industry_user_settings (user_id)');
        $this->addSql('ALTER TABLE industry_user_settings ADD CONSTRAINT FK_IND_SETTINGS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');

        // 6. Migrate ESI data from steps to job_matches
        $this->addSql("
            INSERT INTO industry_step_job_matches (id, step_id, esi_job_id, cost, status, end_date, runs, character_name, matched_at)
            SELECT
                gen_random_uuid(),
                s.id,
                s.esi_job_id,
                s.esi_job_cost,
                COALESCE(s.esi_job_status, 'active'),
                s.esi_job_end_date,
                COALESCE(s.esi_job_runs, s.runs),
                COALESCE(s.esi_job_character_name, 'Unknown'),
                NOW()
            FROM industry_project_steps s
            WHERE s.esi_job_id IS NOT NULL
        ");

        // 7. Alter industry_project_steps: add new columns
        $this->addSql("ALTER TABLE industry_project_steps ADD job_match_mode VARCHAR(20) NOT NULL DEFAULT 'auto'");
        $this->addSql('ALTER TABLE industry_project_steps ADD structure_config_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD CONSTRAINT FK_STEP_STRUCTURE_CONFIG FOREIGN KEY (structure_config_id) REFERENCES industry_structure_configs (id) ON DELETE SET NULL NOT DEFERRABLE');

        // 8. Make me_level/te_level NOT NULL with defaults from project
        $this->addSql('UPDATE industry_project_steps SET me_level = COALESCE(me_level, (SELECT me_level FROM industry_projects WHERE id = project_id)) WHERE me_level IS NULL');
        $this->addSql('UPDATE industry_project_steps SET te_level = COALESCE(te_level, (SELECT te_level FROM industry_projects WHERE id = project_id)) WHERE te_level IS NULL');
        $this->addSql('UPDATE industry_project_steps SET me_level = 10 WHERE me_level IS NULL');
        $this->addSql('UPDATE industry_project_steps SET te_level = 20 WHERE te_level IS NULL');
        $this->addSql('ALTER TABLE industry_project_steps ALTER me_level SET NOT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ALTER me_level SET DEFAULT 10');
        $this->addSql('ALTER TABLE industry_project_steps ALTER te_level SET NOT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ALTER te_level SET DEFAULT 20');

        // 9. Migrate manual_job_data to job_match_mode
        $this->addSql("UPDATE industry_project_steps SET job_match_mode = 'manual' WHERE manual_job_data = true");

        // 10. Drop old columns from industry_project_steps
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS product_type_name');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS esi_job_id');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS esi_job_cost');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS esi_job_status');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS esi_job_end_date');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS esi_job_runs');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS esi_job_character_name');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS esi_job_ids');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS esi_jobs_count');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS esi_jobs_total_runs');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS esi_jobs_active_runs');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS esi_jobs_delivered_runs');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS similar_jobs');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS manual_job_data');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS recommended_structure_name');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS structure_bonus');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS structure_time_bonus');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS time_per_run');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS in_stock');

        // 11. Alter industry_structure_configs: add hidden_by_users
        $this->addSql("ALTER TABLE industry_structure_configs ADD hidden_by_users JSON NOT NULL DEFAULT '[]'");

        // 12. Drop product_type_name from industry_projects
        $this->addSql('ALTER TABLE industry_projects DROP COLUMN IF EXISTS product_type_name');
    }

    public function down(Schema $schema): void
    {
        // Drop new tables
        $this->addSql('ALTER TABLE industry_step_purchases DROP CONSTRAINT IF EXISTS FK_STEP_PURCHASE_TX');
        $this->addSql('ALTER TABLE industry_step_purchases DROP CONSTRAINT IF EXISTS FK_STEP_PURCHASE_STEP');
        $this->addSql('ALTER TABLE industry_step_job_matches DROP CONSTRAINT IF EXISTS FK_STEP_JOB_MATCH_STEP');
        $this->addSql('ALTER TABLE cached_wallet_transactions DROP CONSTRAINT IF EXISTS FK_WALLET_TX_CHAR');
        $this->addSql('ALTER TABLE cached_character_skills DROP CONSTRAINT IF EXISTS FK_CHAR_SKILL_CHAR');
        $this->addSql('ALTER TABLE industry_user_settings DROP CONSTRAINT IF EXISTS FK_IND_SETTINGS_USER');

        $this->addSql('DROP TABLE IF EXISTS industry_step_purchases');
        $this->addSql('DROP TABLE IF EXISTS industry_step_job_matches');
        $this->addSql('DROP TABLE IF EXISTS cached_wallet_transactions');
        $this->addSql('DROP TABLE IF EXISTS cached_character_skills');
        $this->addSql('DROP TABLE IF EXISTS industry_user_settings');

        // Restore dropped columns on industry_project_steps
        $this->addSql('ALTER TABLE industry_project_steps ADD product_type_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD esi_job_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD esi_job_cost DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD esi_job_status VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD esi_job_end_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD esi_job_runs INT DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD esi_job_character_name VARCHAR(100) DEFAULT NULL');
        $this->addSql("ALTER TABLE industry_project_steps ADD esi_job_ids JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE industry_project_steps ADD esi_jobs_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD esi_jobs_total_runs INT DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD esi_jobs_active_runs INT DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD esi_jobs_delivered_runs INT DEFAULT NULL');
        $this->addSql("ALTER TABLE industry_project_steps ADD similar_jobs JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE industry_project_steps ADD manual_job_data BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE industry_project_steps ADD recommended_structure_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD structure_bonus DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD structure_time_bonus DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD time_per_run INT DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ADD in_stock BOOLEAN NOT NULL DEFAULT FALSE');

        // Remove new columns
        $this->addSql('ALTER TABLE industry_project_steps DROP CONSTRAINT IF EXISTS FK_STEP_STRUCTURE_CONFIG');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS job_match_mode');
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN IF EXISTS structure_config_id');

        // Make me_level/te_level nullable again
        $this->addSql('ALTER TABLE industry_project_steps ALTER me_level DROP NOT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ALTER me_level DROP DEFAULT');
        $this->addSql('ALTER TABLE industry_project_steps ALTER te_level DROP NOT NULL');
        $this->addSql('ALTER TABLE industry_project_steps ALTER te_level DROP DEFAULT');

        // Restore hidden_by_users
        $this->addSql('ALTER TABLE industry_structure_configs DROP COLUMN IF EXISTS hidden_by_users');

        // Restore product_type_name on projects
        $this->addSql('ALTER TABLE industry_projects ADD product_type_name VARCHAR(255) DEFAULT NULL');
    }
}
