<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260210121432 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX idx_char_skill_char RENAME TO IDX_5343484A1136BE75');
        $this->addSql('ALTER INDEX uniq_char_skill RENAME TO UNIQ_5343484A1136BE755585C142');
        $this->addSql('ALTER INDEX idx_wallet_tx_char RENAME TO IDX_919DA7D31136BE75');
        $this->addSql('ALTER INDEX idx_wallet_tx_type RENAME TO IDX_919DA7D3C54C8C93');
        $this->addSql('ALTER INDEX idx_wallet_tx_date RENAME TO IDX_919DA7D3AA9E377A');
        $this->addSql('ALTER INDEX uniq_wallet_tx_id RENAME TO UNIQ_919DA7D32FC0CB0F');
        $this->addSql('ALTER TABLE industry_project_steps ALTER me_level DROP DEFAULT');
        $this->addSql('ALTER TABLE industry_project_steps ALTER te_level DROP DEFAULT');
        $this->addSql('ALTER TABLE industry_project_steps ALTER job_match_mode DROP DEFAULT');
        $this->addSql('ALTER TABLE industry_step_job_matches ALTER status TYPE VARCHAR(30)');
        $this->addSql('ALTER TABLE industry_step_job_matches ALTER character_name TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE industry_step_job_matches ALTER character_name DROP NOT NULL');
        $this->addSql('ALTER INDEX idx_step_job_match_step RENAME TO IDX_647755A173B21E9C');
        $this->addSql('ALTER INDEX idx_step_job_match_esi RENAME TO IDX_647755A1BD719596');
        $this->addSql('CREATE INDEX IDX_258023D0C54C8C93 ON industry_step_purchases (type_id)');
        $this->addSql('ALTER INDEX idx_step_purchase_tx RENAME TO IDX_258023D02FC0CB0F');
        $this->addSql('ALTER INDEX idx_step_purchase_step RENAME TO IDX_258023D073B21E9C');
        $this->addSql('ALTER TABLE industry_structure_configs ALTER hidden_by_users DROP DEFAULT');
        $this->addSql('ALTER INDEX uniq_ind_settings_user RENAME TO UNIQ_B4419776A76ED395');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX uniq_5343484a1136be755585c142 RENAME TO uniq_char_skill');
        $this->addSql('ALTER INDEX idx_5343484a1136be75 RENAME TO idx_char_skill_char');
        $this->addSql('ALTER INDEX uniq_919da7d32fc0cb0f RENAME TO uniq_wallet_tx_id');
        $this->addSql('ALTER INDEX idx_919da7d31136be75 RENAME TO idx_wallet_tx_char');
        $this->addSql('ALTER INDEX idx_919da7d3c54c8c93 RENAME TO idx_wallet_tx_type');
        $this->addSql('ALTER INDEX idx_919da7d3aa9e377a RENAME TO idx_wallet_tx_date');
        $this->addSql('ALTER TABLE industry_project_steps ALTER me_level SET DEFAULT 10');
        $this->addSql('ALTER TABLE industry_project_steps ALTER te_level SET DEFAULT 20');
        $this->addSql('ALTER TABLE industry_project_steps ALTER job_match_mode SET DEFAULT \'auto\'');
        $this->addSql('ALTER TABLE industry_step_job_matches ALTER status TYPE VARCHAR(20)');
        $this->addSql('ALTER TABLE industry_step_job_matches ALTER character_name TYPE VARCHAR(100)');
        $this->addSql('ALTER TABLE industry_step_job_matches ALTER character_name SET NOT NULL');
        $this->addSql('ALTER INDEX idx_647755a173b21e9c RENAME TO idx_step_job_match_step');
        $this->addSql('ALTER INDEX idx_647755a1bd719596 RENAME TO idx_step_job_match_esi');
        $this->addSql('DROP INDEX IDX_258023D0C54C8C93');
        $this->addSql('ALTER INDEX idx_258023d02fc0cb0f RENAME TO idx_step_purchase_tx');
        $this->addSql('ALTER INDEX idx_258023d073b21e9c RENAME TO idx_step_purchase_step');
        $this->addSql('ALTER TABLE industry_structure_configs ALTER hidden_by_users SET DEFAULT \'[]\'');
        $this->addSql('ALTER INDEX uniq_b4419776a76ed395 RENAME TO uniq_ind_settings_user');
    }
}
