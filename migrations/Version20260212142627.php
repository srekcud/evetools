<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260212142627 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'V0.6 - Planetary Interaction: colonies, pins, routes, SDE schematics';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE planetary_colonies (id UUID NOT NULL, planet_id INT NOT NULL, planet_type VARCHAR(20) NOT NULL, solar_system_id INT NOT NULL, solar_system_name VARCHAR(100) DEFAULT NULL, upgrade_level SMALLINT NOT NULL, num_pins SMALLINT NOT NULL, last_update TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, cached_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, character_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_BA60423E1136BE75 ON planetary_colonies (character_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BA60423E1136BE75A25E9820 ON planetary_colonies (character_id, planet_id)');
        $this->addSql('CREATE TABLE planetary_pins (id UUID NOT NULL, pin_id BIGINT NOT NULL, type_id INT NOT NULL, type_name VARCHAR(255) DEFAULT NULL, schematic_id INT DEFAULT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, install_time TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, expiry_time TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_cycle_start TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, extractor_product_type_id INT DEFAULT NULL, extractor_cycle_time INT DEFAULT NULL, extractor_qty_per_cycle INT DEFAULT NULL, extractor_head_radius DOUBLE PRECISION DEFAULT NULL, extractor_num_heads SMALLINT DEFAULT NULL, contents JSON DEFAULT NULL, colony_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6BDA3A3B96ADBADE ON planetary_pins (colony_id)');
        $this->addSql('CREATE INDEX IDX_6BDA3A3B33D4B4F7 ON planetary_pins (expiry_time)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6BDA3A3B96ADBADE6C3B254C ON planetary_pins (colony_id, pin_id)');
        $this->addSql('CREATE TABLE planetary_routes (id UUID NOT NULL, route_id INT NOT NULL, source_pin_id BIGINT NOT NULL, destination_pin_id BIGINT NOT NULL, content_type_id INT NOT NULL, quantity DOUBLE PRECISION NOT NULL, waypoints JSON DEFAULT NULL, colony_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2A3E627B96ADBADE ON planetary_routes (colony_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2A3E627B96ADBADE34ECB4E6 ON planetary_routes (colony_id, route_id)');
        $this->addSql('CREATE TABLE sde_planet_schematic_types (type_id INT NOT NULL, is_input BOOLEAN NOT NULL, quantity INT NOT NULL, schematic_id INT NOT NULL, PRIMARY KEY (schematic_id, type_id))');
        $this->addSql('CREATE INDEX IDX_4AE7CAFBE7DE0922 ON sde_planet_schematic_types (schematic_id)');
        $this->addSql('CREATE INDEX IDX_4AE7CAFBC54C8C93 ON sde_planet_schematic_types (type_id)');
        $this->addSql('CREATE TABLE sde_planet_schematics (schematic_id INT NOT NULL, schematic_name VARCHAR(255) NOT NULL, cycle_time INT NOT NULL, PRIMARY KEY (schematic_id))');
        $this->addSql('ALTER TABLE planetary_colonies ADD CONSTRAINT FK_BA60423E1136BE75 FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE planetary_pins ADD CONSTRAINT FK_6BDA3A3B96ADBADE FOREIGN KEY (colony_id) REFERENCES planetary_colonies (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE planetary_routes ADD CONSTRAINT FK_2A3E627B96ADBADE FOREIGN KEY (colony_id) REFERENCES planetary_colonies (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE sde_planet_schematic_types ADD CONSTRAINT FK_4AE7CAFBE7DE0922 FOREIGN KEY (schematic_id) REFERENCES sde_planet_schematics (schematic_id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE planetary_colonies DROP CONSTRAINT FK_BA60423E1136BE75');
        $this->addSql('ALTER TABLE planetary_pins DROP CONSTRAINT FK_6BDA3A3B96ADBADE');
        $this->addSql('ALTER TABLE planetary_routes DROP CONSTRAINT FK_2A3E627B96ADBADE');
        $this->addSql('ALTER TABLE sde_planet_schematic_types DROP CONSTRAINT FK_4AE7CAFBE7DE0922');
        $this->addSql('DROP TABLE planetary_colonies');
        $this->addSql('DROP TABLE planetary_pins');
        $this->addSql('DROP TABLE planetary_routes');
        $this->addSql('DROP TABLE sde_planet_schematic_types');
        $this->addSql('DROP TABLE sde_planet_schematics');
    }
}
