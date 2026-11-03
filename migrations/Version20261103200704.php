<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103200704 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the fleet storage recipient and container of a mining operation, the declaration of its members and the delivered value of its frozen mined values';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_frozen_mined_values ADD delivered_value DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE mining_operation_members ADD fleet_storage_delivery VARCHAR(10) DEFAULT \'none\' NOT NULL');
        $this->addSql('ALTER TABLE mining_operation_members ADD fleet_storage_quantities JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD fleet_storage_container_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD fleet_storage_recipient_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD CONSTRAINT FK_494FEAB245AF3504 FOREIGN KEY (fleet_storage_recipient_id) REFERENCES mining_operation_members (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_494FEAB245AF3504 ON mining_operations (fleet_storage_recipient_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_frozen_mined_values DROP delivered_value');
        $this->addSql('ALTER TABLE mining_operation_members DROP fleet_storage_delivery');
        $this->addSql('ALTER TABLE mining_operation_members DROP fleet_storage_quantities');
        $this->addSql('ALTER TABLE mining_operations DROP CONSTRAINT FK_494FEAB245AF3504');
        $this->addSql('DROP INDEX IDX_494FEAB245AF3504');
        $this->addSql('ALTER TABLE mining_operations DROP fleet_storage_container_name');
        $this->addSql('ALTER TABLE mining_operations DROP fleet_storage_recipient_id');
    }
}
