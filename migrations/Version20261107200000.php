<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the table of the blueprints owned by characters and corporations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE owned_blueprints (id UUID NOT NULL, item_id BIGINT NOT NULL, type_id INT NOT NULL, location_id BIGINT NOT NULL, location_flag VARCHAR(50) NOT NULL, quantity INT NOT NULL, material_efficiency INT NOT NULL, time_efficiency INT NOT NULL, runs INT NOT NULL, corporation_id BIGINT DEFAULT NULL, division_number SMALLINT DEFAULT NULL, synced_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, character_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_EB69FDD1136BE75 ON owned_blueprints (character_id)');
        $this->addSql('CREATE INDEX IDX_EB69FDDB2685369 ON owned_blueprints (corporation_id)');
        $this->addSql('CREATE INDEX IDX_EB69FDDC54C8C93 ON owned_blueprints (type_id)');
        $this->addSql('ALTER TABLE owned_blueprints ADD CONSTRAINT FK_EB69FDD1136BE75 FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The blueprints read from the ESI are refilled by the next sync, not restored.');
    }
}
