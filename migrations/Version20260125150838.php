<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260125150838 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create SDE (Static Data Export) tables for EVE Online data';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sde_inv_categories (category_id INT NOT NULL, category_name VARCHAR(255) NOT NULL, published BOOLEAN NOT NULL, icon_id INT DEFAULT NULL, PRIMARY KEY (category_id))');
        $this->addSql('CREATE TABLE sde_inv_groups (group_id INT NOT NULL, group_name VARCHAR(255) NOT NULL, published BOOLEAN NOT NULL, icon_id INT DEFAULT NULL, use_base_price BOOLEAN NOT NULL, anchored BOOLEAN NOT NULL, anchorable BOOLEAN NOT NULL, fittable_non_singleton BOOLEAN NOT NULL, category_id INT NOT NULL, PRIMARY KEY (group_id))');
        $this->addSql('CREATE INDEX IDX_9516817D12469DE2 ON sde_inv_groups (category_id)');
        $this->addSql('CREATE TABLE sde_inv_market_groups (market_group_id INT NOT NULL, market_group_name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, icon_id INT DEFAULT NULL, has_types BOOLEAN NOT NULL, parent_group_id INT DEFAULT NULL, PRIMARY KEY (market_group_id))');
        $this->addSql('CREATE INDEX IDX_C57DB44B61997596 ON sde_inv_market_groups (parent_group_id)');
        $this->addSql('CREATE TABLE sde_inv_types (type_id INT NOT NULL, type_name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, mass DOUBLE PRECISION DEFAULT NULL, volume DOUBLE PRECISION DEFAULT NULL, capacity DOUBLE PRECISION DEFAULT NULL, portion_size INT DEFAULT NULL, base_price NUMERIC(20, 2) DEFAULT NULL, published BOOLEAN NOT NULL, icon_id INT DEFAULT NULL, graphic_id INT DEFAULT NULL, race_id INT DEFAULT NULL, sof_faction_name INT DEFAULT NULL, sound_id INT DEFAULT NULL, group_id INT NOT NULL, market_group_id INT DEFAULT NULL, PRIMARY KEY (type_id))');
        $this->addSql('CREATE INDEX IDX_92514268FE54D947 ON sde_inv_types (group_id)');
        $this->addSql('CREATE INDEX IDX_9251426813E5CC8F ON sde_inv_types (market_group_id)');
        $this->addSql('CREATE INDEX IDX_92514268683C6017 ON sde_inv_types (published)');
        $this->addSql('CREATE TABLE sde_map_constellations (constellation_id INT NOT NULL, constellation_name VARCHAR(255) NOT NULL, x DOUBLE PRECISION DEFAULT NULL, y DOUBLE PRECISION DEFAULT NULL, z DOUBLE PRECISION DEFAULT NULL, x_min DOUBLE PRECISION DEFAULT NULL, x_max DOUBLE PRECISION DEFAULT NULL, y_min DOUBLE PRECISION DEFAULT NULL, y_max DOUBLE PRECISION DEFAULT NULL, z_min DOUBLE PRECISION DEFAULT NULL, z_max DOUBLE PRECISION DEFAULT NULL, faction_id INT DEFAULT NULL, radius DOUBLE PRECISION DEFAULT NULL, region_id INT NOT NULL, PRIMARY KEY (constellation_id))');
        $this->addSql('CREATE INDEX IDX_521D710398260155 ON sde_map_constellations (region_id)');
        $this->addSql('CREATE TABLE sde_map_regions (region_id INT NOT NULL, region_name VARCHAR(255) NOT NULL, x DOUBLE PRECISION DEFAULT NULL, y DOUBLE PRECISION DEFAULT NULL, z DOUBLE PRECISION DEFAULT NULL, x_min DOUBLE PRECISION DEFAULT NULL, x_max DOUBLE PRECISION DEFAULT NULL, y_min DOUBLE PRECISION DEFAULT NULL, y_max DOUBLE PRECISION DEFAULT NULL, z_min DOUBLE PRECISION DEFAULT NULL, z_max DOUBLE PRECISION DEFAULT NULL, faction_id INT DEFAULT NULL, radius DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY (region_id))');
        $this->addSql('CREATE TABLE sde_map_solar_systems (solar_system_id INT NOT NULL, solar_system_name VARCHAR(255) NOT NULL, region_id INT NOT NULL, x DOUBLE PRECISION DEFAULT NULL, y DOUBLE PRECISION DEFAULT NULL, z DOUBLE PRECISION DEFAULT NULL, x_min DOUBLE PRECISION DEFAULT NULL, x_max DOUBLE PRECISION DEFAULT NULL, y_min DOUBLE PRECISION DEFAULT NULL, y_max DOUBLE PRECISION DEFAULT NULL, z_min DOUBLE PRECISION DEFAULT NULL, z_max DOUBLE PRECISION DEFAULT NULL, security DOUBLE PRECISION NOT NULL, true_security_status DOUBLE PRECISION DEFAULT NULL, faction_id INT DEFAULT NULL, radius DOUBLE PRECISION DEFAULT NULL, sun_type_id INT DEFAULT NULL, security_class VARCHAR(50) DEFAULT NULL, border BOOLEAN NOT NULL, fringe BOOLEAN NOT NULL, corridor BOOLEAN NOT NULL, hub BOOLEAN NOT NULL, international BOOLEAN NOT NULL, regional BOOLEAN NOT NULL, constellation_id INT NOT NULL, PRIMARY KEY (solar_system_id))');
        $this->addSql('CREATE INDEX IDX_525971C5AFB95E03 ON sde_map_solar_systems (constellation_id)');
        $this->addSql('CREATE INDEX IDX_525971C598260155 ON sde_map_solar_systems (region_id)');
        $this->addSql('CREATE INDEX IDX_525971C5C59BD5C1 ON sde_map_solar_systems (security)');
        $this->addSql('CREATE TABLE sde_sta_stations (station_id BIGINT NOT NULL, station_name VARCHAR(255) NOT NULL, constellation_id INT NOT NULL, region_id INT NOT NULL, station_type_id INT DEFAULT NULL, corporation_id INT DEFAULT NULL, x DOUBLE PRECISION DEFAULT NULL, y DOUBLE PRECISION DEFAULT NULL, z DOUBLE PRECISION DEFAULT NULL, security DOUBLE PRECISION DEFAULT NULL, docking_cost_per_volume DOUBLE PRECISION DEFAULT NULL, max_ship_volume_dockable DOUBLE PRECISION DEFAULT NULL, office_rental_cost INT DEFAULT NULL, reprocessing_efficiency DOUBLE PRECISION DEFAULT NULL, reprocessing_stations_take DOUBLE PRECISION DEFAULT NULL, operation_id INT DEFAULT NULL, solar_system_id INT NOT NULL, PRIMARY KEY (station_id))');
        $this->addSql('CREATE INDEX IDX_B8E75622E5C8C6D3 ON sde_sta_stations (solar_system_id)');
        $this->addSql('CREATE INDEX IDX_B8E75622AFB95E03 ON sde_sta_stations (constellation_id)');
        $this->addSql('CREATE INDEX IDX_B8E7562298260155 ON sde_sta_stations (region_id)');
        $this->addSql('CREATE INDEX IDX_B8E75622B2685369 ON sde_sta_stations (corporation_id)');
        $this->addSql('ALTER TABLE sde_inv_groups ADD CONSTRAINT FK_9516817D12469DE2 FOREIGN KEY (category_id) REFERENCES sde_inv_categories (category_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE sde_inv_market_groups ADD CONSTRAINT FK_C57DB44B61997596 FOREIGN KEY (parent_group_id) REFERENCES sde_inv_market_groups (market_group_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE sde_inv_types ADD CONSTRAINT FK_92514268FE54D947 FOREIGN KEY (group_id) REFERENCES sde_inv_groups (group_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE sde_inv_types ADD CONSTRAINT FK_9251426813E5CC8F FOREIGN KEY (market_group_id) REFERENCES sde_inv_market_groups (market_group_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE sde_map_constellations ADD CONSTRAINT FK_521D710398260155 FOREIGN KEY (region_id) REFERENCES sde_map_regions (region_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE sde_map_solar_systems ADD CONSTRAINT FK_525971C5AFB95E03 FOREIGN KEY (constellation_id) REFERENCES sde_map_constellations (constellation_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE sde_sta_stations ADD CONSTRAINT FK_B8E75622E5C8C6D3 FOREIGN KEY (solar_system_id) REFERENCES sde_map_solar_systems (solar_system_id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sde_inv_groups DROP CONSTRAINT FK_9516817D12469DE2');
        $this->addSql('ALTER TABLE sde_inv_market_groups DROP CONSTRAINT FK_C57DB44B61997596');
        $this->addSql('ALTER TABLE sde_inv_types DROP CONSTRAINT FK_92514268FE54D947');
        $this->addSql('ALTER TABLE sde_inv_types DROP CONSTRAINT FK_9251426813E5CC8F');
        $this->addSql('ALTER TABLE sde_map_constellations DROP CONSTRAINT FK_521D710398260155');
        $this->addSql('ALTER TABLE sde_map_solar_systems DROP CONSTRAINT FK_525971C5AFB95E03');
        $this->addSql('ALTER TABLE sde_sta_stations DROP CONSTRAINT FK_B8E75622E5C8C6D3');
        $this->addSql('DROP TABLE sde_inv_categories');
        $this->addSql('DROP TABLE sde_inv_groups');
        $this->addSql('DROP TABLE sde_inv_market_groups');
        $this->addSql('DROP TABLE sde_inv_types');
        $this->addSql('DROP TABLE sde_map_constellations');
        $this->addSql('DROP TABLE sde_map_regions');
        $this->addSql('DROP TABLE sde_map_solar_systems');
        $this->addSql('DROP TABLE sde_sta_stations');
    }
}
