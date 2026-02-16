<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260216091158 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create profit_matches and profit_settings tables for Profit Tracker module';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE profit_matches (id UUID NOT NULL, product_type_id INT NOT NULL, job_runs INT NOT NULL, quantity_sold INT NOT NULL, material_cost DOUBLE PRECISION NOT NULL, job_install_cost DOUBLE PRECISION NOT NULL, tax_amount DOUBLE PRECISION NOT NULL, revenue DOUBLE PRECISION NOT NULL, profit DOUBLE PRECISION NOT NULL, cost_source VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, matched_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, job_id UUID DEFAULT NULL, transaction_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_942A3A82A76ED395 ON profit_matches (user_id)');
        $this->addSql('CREATE INDEX IDX_942A3A82BE04EA9 ON profit_matches (job_id)');
        $this->addSql('CREATE INDEX IDX_942A3A822FC0CB0F ON profit_matches (transaction_id)');
        $this->addSql('CREATE INDEX IDX_942A3A82A76ED39514959723 ON profit_matches (user_id, product_type_id)');
        $this->addSql('CREATE INDEX IDX_942A3A82A76ED39553902853 ON profit_matches (user_id, matched_at)');
        $this->addSql('CREATE TABLE profit_settings (id UUID NOT NULL, sales_tax_rate DOUBLE PRECISION NOT NULL, default_cost_source VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CDD51474A76ED395 ON profit_settings (user_id)');
        $this->addSql('ALTER TABLE profit_matches ADD CONSTRAINT FK_942A3A82A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE profit_matches ADD CONSTRAINT FK_942A3A82BE04EA9 FOREIGN KEY (job_id) REFERENCES cached_industry_jobs (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE profit_matches ADD CONSTRAINT FK_942A3A822FC0CB0F FOREIGN KEY (transaction_id) REFERENCES cached_wallet_transactions (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE profit_settings ADD CONSTRAINT FK_CDD51474A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE profit_matches DROP CONSTRAINT FK_942A3A82A76ED395');
        $this->addSql('ALTER TABLE profit_matches DROP CONSTRAINT FK_942A3A82BE04EA9');
        $this->addSql('ALTER TABLE profit_matches DROP CONSTRAINT FK_942A3A822FC0CB0F');
        $this->addSql('ALTER TABLE profit_settings DROP CONSTRAINT FK_CDD51474A76ED395');
        $this->addSql('DROP TABLE profit_matches');
        $this->addSql('DROP TABLE profit_settings');
    }
}
