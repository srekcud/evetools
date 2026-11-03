<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103162206 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ledger snapshots, snapshot lines and quantity corrections to mining operations, with their start, end, solar system and departure';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mining_ledger_snapshot_lines (id UUID NOT NULL, date DATE NOT NULL, type_id INT NOT NULL, solar_system_id INT NOT NULL, quantity BIGINT NOT NULL, snapshot_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D5E097007B39395E ON mining_ledger_snapshot_lines (snapshot_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D5E097007B39395EAA9E377AC54C8C93E5C8C6D3 ON mining_ledger_snapshot_lines (snapshot_id, date, type_id, solar_system_id)');
        $this->addSql('CREATE TABLE mining_ledger_snapshots (id UUID NOT NULL, status VARCHAR(20) NOT NULL, ready_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, taken_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, esi_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, failure_reason VARCHAR(255) DEFAULT NULL, kind VARCHAR(10) NOT NULL, reference_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, operation_character_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_EC6F3494F8AD3779 ON mining_ledger_snapshots (operation_character_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EC6F3494F8AD37793BC4BCD9 ON mining_ledger_snapshots (operation_character_id, kind)');
        $this->addSql('CREATE TABLE mining_quantity_corrections (id UUID NOT NULL, type_id INT NOT NULL, original_quantity BIGINT DEFAULT NULL, corrected_quantity BIGINT NOT NULL, corrected_by_name VARCHAR(255) DEFAULT NULL, corrected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, operation_character_id UUID NOT NULL, corrected_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_158F3EA4F8AD3779 ON mining_quantity_corrections (operation_character_id)');
        $this->addSql('CREATE INDEX IDX_158F3EA48CF37DC4 ON mining_quantity_corrections (corrected_by_id)');
        $this->addSql('CREATE INDEX IDX_158F3EA4F8AD3779C54C8C93 ON mining_quantity_corrections (operation_character_id, type_id)');
        $this->addSql('ALTER TABLE mining_ledger_snapshot_lines ADD CONSTRAINT FK_D5E097007B39395E FOREIGN KEY (snapshot_id) REFERENCES mining_ledger_snapshots (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_ledger_snapshots ADD CONSTRAINT FK_EC6F3494F8AD3779 FOREIGN KEY (operation_character_id) REFERENCES mining_operation_characters (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_quantity_corrections ADD CONSTRAINT FK_158F3EA4F8AD3779 FOREIGN KEY (operation_character_id) REFERENCES mining_operation_characters (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_quantity_corrections ADD CONSTRAINT FK_158F3EA48CF37DC4 FOREIGN KEY (corrected_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_operation_members ADD departed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD solar_system_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD solar_system_name VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_ledger_snapshot_lines DROP CONSTRAINT FK_D5E097007B39395E');
        $this->addSql('ALTER TABLE mining_ledger_snapshots DROP CONSTRAINT FK_EC6F3494F8AD3779');
        $this->addSql('ALTER TABLE mining_quantity_corrections DROP CONSTRAINT FK_158F3EA4F8AD3779');
        $this->addSql('ALTER TABLE mining_quantity_corrections DROP CONSTRAINT FK_158F3EA48CF37DC4');
        $this->addSql('DROP TABLE mining_ledger_snapshot_lines');
        $this->addSql('DROP TABLE mining_ledger_snapshots');
        $this->addSql('DROP TABLE mining_quantity_corrections');
        $this->addSql('ALTER TABLE mining_operation_members DROP departed_at');
        $this->addSql('ALTER TABLE mining_operations DROP started_at');
        $this->addSql('ALTER TABLE mining_operations DROP ended_at');
        $this->addSql('ALTER TABLE mining_operations DROP solar_system_id');
        $this->addSql('ALTER TABLE mining_operations DROP solar_system_name');
    }
}
