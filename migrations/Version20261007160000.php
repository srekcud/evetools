<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #75: replaying every migration on an empty database left a schema that drifted from
 * the entity mapping (columns added and defaults/comments/index names fixed outside migrations).
 * Every statement is idempotent, so it changes nothing on a database already aligned (prod, dev).
 */
final class Version20261007160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align the schema built by replaying the migrations with the entity mapping (idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_assets ADD COLUMN IF NOT EXISTS solar_system_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cached_assets ADD COLUMN IF NOT EXISTS solar_system_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE cached_assets ADD COLUMN IF NOT EXISTS item_name VARCHAR(255) DEFAULT NULL');

        $this->addSql('ALTER TABLE user_pve_settings ALTER declined_contract_ids DROP DEFAULT');
        $this->addSql('ALTER TABLE user_pve_settings ALTER declined_transaction_ids DROP DEFAULT');
        $this->addSql('ALTER TABLE user_pve_settings ALTER declined_loot_sale_transaction_ids DROP DEFAULT');
        $this->addSql('ALTER TABLE user_pve_settings ALTER loot_type_ids DROP DEFAULT');
        $this->addSql('ALTER TABLE user_pve_settings ALTER auto_sync_enabled DROP DEFAULT');

        $this->addSql('COMMENT ON COLUMN user_pve_settings.last_sync_at IS NULL');
        $this->addSql('COMMENT ON COLUMN pve_income.id IS NULL');
        $this->addSql('COMMENT ON COLUMN pve_income.user_id IS NULL');
        $this->addSql('COMMENT ON COLUMN pve_income.date IS NULL');
        $this->addSql('COMMENT ON COLUMN pve_income.created_at IS NULL');

        $this->addSql('ALTER INDEX IF EXISTS idx_pve_income_user_date RENAME TO IDX_5586A343A76ED395AA9E377A');
        $this->addSql('ALTER INDEX IF EXISTS idx_pve_income_user_transaction RENAME TO IDX_5586A343A76ED3952FC0CB0F');
        $this->addSql('ALTER INDEX IF EXISTS idx_pve_income_journal RENAME TO IDX_5586A343A76ED3956A86E4FB');
    }

    public function down(Schema $schema): void
    {
        // Intentionally empty: on prod and dev these columns, defaults, comments and index names
        // were already in their mapped state before this migration, so reverting it must not
        // drop or rename anything that existed beforehand.
    }
}
