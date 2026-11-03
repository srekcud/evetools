<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103224822 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the recurrences of mining operations and link an operation to its recurrence and to its chunk';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mining_operation_recurrences (id UUID NOT NULL, kind VARCHAR(20) NOT NULL, label VARCHAR(100) NOT NULL, visibility VARCHAR(20) NOT NULL, split_mode VARCHAR(30) NOT NULL, corporation_id BIGINT DEFAULT NULL, structure_id BIGINT DEFAULT NULL, structure_name VARCHAR(255) DEFAULT NULL, anchor_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, stopped_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5A9DB1347E3C61F9 ON mining_operation_recurrences (owner_id)');
        $this->addSql('ALTER TABLE mining_operation_recurrences ADD CONSTRAINT FK_5A9DB1347E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_operations ADD recurrence_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD chunk_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD CONSTRAINT FK_494FEAB22C414CE8 FOREIGN KEY (recurrence_id) REFERENCES mining_operation_recurrences (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_operations ADD CONSTRAINT FK_494FEAB28BD543CD FOREIGN KEY (chunk_id) REFERENCES corporation_mining_extractions (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_494FEAB22C414CE8 ON mining_operations (recurrence_id)');
        $this->addSql('CREATE INDEX IDX_494FEAB28BD543CD ON mining_operations (chunk_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_operation_recurrences DROP CONSTRAINT FK_5A9DB1347E3C61F9');
        $this->addSql('DROP TABLE mining_operation_recurrences');
        $this->addSql('ALTER TABLE mining_operations DROP CONSTRAINT FK_494FEAB22C414CE8');
        $this->addSql('ALTER TABLE mining_operations DROP CONSTRAINT FK_494FEAB28BD543CD');
        $this->addSql('DROP INDEX IDX_494FEAB22C414CE8');
        $this->addSql('DROP INDEX IDX_494FEAB28BD543CD');
        $this->addSql('ALTER TABLE mining_operations DROP recurrence_id');
        $this->addSql('ALTER TABLE mining_operations DROP chunk_id');
    }
}
