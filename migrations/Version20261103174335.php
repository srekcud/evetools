<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103174335 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add valuation settings, frozen shares status, frozen unit prices and frozen mined values to mining operations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mining_frozen_mined_values (id UUID NOT NULL, participant_name VARCHAR(255) DEFAULT NULL, position SMALLINT NOT NULL, mined_value DOUBLE PRECISION NOT NULL, operation_id UUID NOT NULL, member_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_AF03F344AC3583 ON mining_frozen_mined_values (operation_id)');
        $this->addSql('CREATE INDEX IDX_AF03F37597D3FE ON mining_frozen_mined_values (member_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AF03F344AC3583462CE4F5 ON mining_frozen_mined_values (operation_id, position)');
        $this->addSql('CREATE TABLE mining_frozen_unit_prices (id UUID NOT NULL, type_id INT NOT NULL, unit_price DOUBLE PRECISION NOT NULL, operation_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5B10C98744AC3583 ON mining_frozen_unit_prices (operation_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5B10C98744AC3583C54C8C93 ON mining_frozen_unit_prices (operation_id, type_id)');
        $this->addSql('ALTER TABLE mining_frozen_mined_values ADD CONSTRAINT FK_AF03F344AC3583 FOREIGN KEY (operation_id) REFERENCES mining_operations (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_frozen_mined_values ADD CONSTRAINT FK_AF03F37597D3FE FOREIGN KEY (member_id) REFERENCES mining_operation_members (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_frozen_unit_prices ADD CONSTRAINT FK_5B10C98744AC3583 FOREIGN KEY (operation_id) REFERENCES mining_operations (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_operations ADD valuation_form VARCHAR(20) DEFAULT \'compressed\' NOT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD valuation_market VARCHAR(20) DEFAULT \'jita\' NOT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD valuation_side VARCHAR(10) DEFAULT \'buy\' NOT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD valuation_structure_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD valuation_structure_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD reprocess_yield DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD booster_cut_percent SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD corporation_cut_percent SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD shares_frozen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD prices_synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD booster_member_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD CONSTRAINT FK_494FEAB2EE563084 FOREIGN KEY (booster_member_id) REFERENCES mining_operation_members (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_494FEAB2EE563084 ON mining_operations (booster_member_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_frozen_mined_values DROP CONSTRAINT FK_AF03F344AC3583');
        $this->addSql('ALTER TABLE mining_frozen_mined_values DROP CONSTRAINT FK_AF03F37597D3FE');
        $this->addSql('ALTER TABLE mining_frozen_unit_prices DROP CONSTRAINT FK_5B10C98744AC3583');
        $this->addSql('DROP TABLE mining_frozen_mined_values');
        $this->addSql('DROP TABLE mining_frozen_unit_prices');
        $this->addSql('ALTER TABLE mining_operations DROP CONSTRAINT FK_494FEAB2EE563084');
        $this->addSql('DROP INDEX IDX_494FEAB2EE563084');
        $this->addSql('ALTER TABLE mining_operations DROP valuation_form');
        $this->addSql('ALTER TABLE mining_operations DROP valuation_market');
        $this->addSql('ALTER TABLE mining_operations DROP valuation_side');
        $this->addSql('ALTER TABLE mining_operations DROP valuation_structure_id');
        $this->addSql('ALTER TABLE mining_operations DROP valuation_structure_name');
        $this->addSql('ALTER TABLE mining_operations DROP reprocess_yield');
        $this->addSql('ALTER TABLE mining_operations DROP booster_cut_percent');
        $this->addSql('ALTER TABLE mining_operations DROP corporation_cut_percent');
        $this->addSql('ALTER TABLE mining_operations DROP shares_frozen_at');
        $this->addSql('ALTER TABLE mining_operations DROP prices_synced_at');
        $this->addSql('ALTER TABLE mining_operations DROP booster_member_id');
    }
}
