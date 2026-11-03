<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create corporation_mining_extractions and corporation_mining_sync_states for the corporation moon chunk calendar';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE corporation_mining_extractions (id UUID NOT NULL, corporation_id BIGINT NOT NULL, structure_id BIGINT NOT NULL, moon_id BIGINT NOT NULL, extraction_start_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, chunk_arrival_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, natural_decay_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, structure_name VARCHAR(255) DEFAULT NULL, solar_system_id INT DEFAULT NULL, solar_system_name VARCHAR(255) DEFAULT NULL, moon_name VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_corporation_mining_extraction ON corporation_mining_extractions (corporation_id, structure_id, moon_id, extraction_start_time)');
        $this->addSql('CREATE TABLE corporation_mining_sync_states (id UUID NOT NULL, corporation_id BIGINT NOT NULL, last_success_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_failure VARCHAR(32) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_53DA1C33B2685369 ON corporation_mining_sync_states (corporation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE corporation_mining_sync_states');
        $this->addSql('DROP TABLE corporation_mining_extractions');
    }
}
