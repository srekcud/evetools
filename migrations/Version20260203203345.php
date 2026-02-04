<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260203203345 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create mining_entries and user_ledger_settings tables for Ledger module V0.3';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mining_entries (id UUID NOT NULL, character_id BIGINT NOT NULL, character_name VARCHAR(255) NOT NULL, date DATE NOT NULL, type_id INT NOT NULL, type_name VARCHAR(255) NOT NULL, solar_system_id INT NOT NULL, solar_system_name VARCHAR(255) NOT NULL, quantity BIGINT NOT NULL, unit_price DOUBLE PRECISION DEFAULT NULL, total_value DOUBLE PRECISION DEFAULT NULL, usage VARCHAR(20) NOT NULL, linked_project_id VARCHAR(36) DEFAULT NULL, linked_corp_project_id INT DEFAULT NULL, synced_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_42BE14D7A76ED395 ON mining_entries (user_id)');
        $this->addSql('CREATE INDEX IDX_42BE14D7A76ED395AA9E377A ON mining_entries (user_id, date)');
        $this->addSql('CREATE INDEX IDX_42BE14D7A76ED395D0EB5E70 ON mining_entries (user_id, usage)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_42BE14D7A76ED3951136BE75AA9E377AC54C8C93E5C8C6D3 ON mining_entries (user_id, character_id, date, type_id, solar_system_id)');
        $this->addSql('CREATE TABLE user_ledger_settings (id UUID NOT NULL, last_mining_sync_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, auto_sync_enabled BOOLEAN NOT NULL, corp_project_accounting VARCHAR(10) NOT NULL, excluded_type_ids JSON NOT NULL, default_sold_type_ids JSON NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A689C9B0A76ED395 ON user_ledger_settings (user_id)');
        $this->addSql('ALTER TABLE mining_entries ADD CONSTRAINT FK_42BE14D7A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_ledger_settings ADD CONSTRAINT FK_A689C9B0A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE industry_project_steps ALTER in_stock_quantity DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mining_entries DROP CONSTRAINT FK_42BE14D7A76ED395');
        $this->addSql('ALTER TABLE user_ledger_settings DROP CONSTRAINT FK_A689C9B0A76ED395');
        $this->addSql('DROP TABLE mining_entries');
        $this->addSql('DROP TABLE user_ledger_settings');
        $this->addSql('ALTER TABLE industry_project_steps ALTER in_stock_quantity SET DEFAULT 0');
    }
}
