<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260125154137 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Ansiblex jump gates table for player-owned jump bridges';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ansiblex_jump_gates (structure_id BIGINT NOT NULL, name VARCHAR(255) NOT NULL, source_solar_system_id INT NOT NULL, source_solar_system_name VARCHAR(255) NOT NULL, destination_solar_system_id INT NOT NULL, destination_solar_system_name VARCHAR(255) NOT NULL, owner_alliance_id INT DEFAULT NULL, owner_alliance_name VARCHAR(255) DEFAULT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (structure_id))');
        $this->addSql('CREATE INDEX IDX_D181CB3C43D7E8D8 ON ansiblex_jump_gates (source_solar_system_id)');
        $this->addSql('CREATE INDEX IDX_D181CB3CC1210604 ON ansiblex_jump_gates (destination_solar_system_id)');
        $this->addSql('CREATE INDEX IDX_D181CB3C164435B9 ON ansiblex_jump_gates (owner_alliance_id)');
        $this->addSql('CREATE INDEX IDX_D181CB3C1B5771DD ON ansiblex_jump_gates (is_active)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D181CB3C43D7E8D8C1210604 ON ansiblex_jump_gates (source_solar_system_id, destination_solar_system_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE ansiblex_jump_gates');
    }
}
