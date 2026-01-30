<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260125161935 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sde_chr_factions (faction_id INT NOT NULL, faction_name VARCHAR(100) NOT NULL, description TEXT DEFAULT NULL, race_ids INT DEFAULT NULL, solar_system_id INT DEFAULT NULL, corporation_id INT DEFAULT NULL, size_factor DOUBLE PRECISION DEFAULT NULL, station_count INT DEFAULT NULL, station_system_count INT DEFAULT NULL, militia_corporation_id INT DEFAULT NULL, icon_id INT DEFAULT NULL, PRIMARY KEY (faction_id))');
        $this->addSql('CREATE TABLE sde_chr_races (race_id INT NOT NULL, race_name VARCHAR(100) NOT NULL, description TEXT DEFAULT NULL, icon_id INT DEFAULT NULL, short_description TEXT DEFAULT NULL, PRIMARY KEY (race_id))');
        $this->addSql('CREATE TABLE sde_dgm_attribute_types (attribute_id INT NOT NULL, attribute_name VARCHAR(255) DEFAULT NULL, description TEXT DEFAULT NULL, icon_id INT DEFAULT NULL, default_value DOUBLE PRECISION DEFAULT NULL, published BOOLEAN NOT NULL, display_name VARCHAR(255) DEFAULT NULL, unit_id INT DEFAULT NULL, stackable BOOLEAN NOT NULL, high_is_good BOOLEAN NOT NULL, category_id INT DEFAULT NULL, PRIMARY KEY (attribute_id))');
        $this->addSql('CREATE TABLE sde_dgm_effects (effect_id INT NOT NULL, effect_name VARCHAR(255) DEFAULT NULL, effect_category INT DEFAULT NULL, pre_expression INT DEFAULT NULL, post_expression INT DEFAULT NULL, description TEXT DEFAULT NULL, guid VARCHAR(255) DEFAULT NULL, icon_id INT DEFAULT NULL, is_offensive BOOLEAN NOT NULL, is_assistance BOOLEAN NOT NULL, duration_attribute_id INT DEFAULT NULL, tracking_speed_attribute_id INT DEFAULT NULL, discharge_attribute_id INT DEFAULT NULL, range_attribute_id INT DEFAULT NULL, falloff_attribute_id INT DEFAULT NULL, disallow_auto_repeat BOOLEAN NOT NULL, published BOOLEAN NOT NULL, display_name VARCHAR(255) DEFAULT NULL, is_warp_safe BOOLEAN NOT NULL, range_chance BOOLEAN NOT NULL, electronic_chance BOOLEAN NOT NULL, propulsion_chance BOOLEAN NOT NULL, distribution INT DEFAULT NULL, sfx_name VARCHAR(255) DEFAULT NULL, npc_usage_chance_attribute_id INT DEFAULT NULL, npc_activation_chance_attribute_id INT DEFAULT NULL, fitting_usage_chance_attribute_id INT DEFAULT NULL, modifier_info TEXT DEFAULT NULL, PRIMARY KEY (effect_id))');
        $this->addSql('CREATE TABLE sde_dgm_type_attributes (type_id INT NOT NULL, attribute_id INT NOT NULL, value_int INT DEFAULT NULL, value_float DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY (type_id, attribute_id))');
        $this->addSql('CREATE INDEX idx_attribute ON sde_dgm_type_attributes (attribute_id)');
        $this->addSql('CREATE TABLE sde_dgm_type_effects (type_id INT NOT NULL, effect_id INT NOT NULL, is_default BOOLEAN NOT NULL, PRIMARY KEY (type_id, effect_id))');
        $this->addSql('CREATE INDEX idx_effect ON sde_dgm_type_effects (effect_id)');
        $this->addSql('CREATE TABLE sde_eve_icons (icon_id INT NOT NULL, icon_file VARCHAR(500) NOT NULL, description TEXT DEFAULT NULL, PRIMARY KEY (icon_id))');
        $this->addSql('CREATE TABLE sde_industry_activities (type_id INT NOT NULL, activity_id INT NOT NULL, time INT NOT NULL, PRIMARY KEY (type_id, activity_id))');
        $this->addSql('CREATE TABLE sde_industry_activity_materials (type_id INT NOT NULL, activity_id INT NOT NULL, material_type_id INT NOT NULL, quantity INT NOT NULL, PRIMARY KEY (type_id, activity_id, material_type_id))');
        $this->addSql('CREATE INDEX idx_material_type ON sde_industry_activity_materials (material_type_id)');
        $this->addSql('CREATE TABLE sde_industry_activity_products (type_id INT NOT NULL, activity_id INT NOT NULL, product_type_id INT NOT NULL, quantity INT NOT NULL, PRIMARY KEY (type_id, activity_id, product_type_id))');
        $this->addSql('CREATE INDEX idx_product_type ON sde_industry_activity_products (product_type_id)');
        $this->addSql('CREATE TABLE sde_industry_activity_skills (type_id INT NOT NULL, activity_id INT NOT NULL, skill_id INT NOT NULL, level INT NOT NULL, PRIMARY KEY (type_id, activity_id, skill_id))');
        $this->addSql('CREATE INDEX idx_skill ON sde_industry_activity_skills (skill_id)');
        $this->addSql('CREATE TABLE sde_industry_blueprints (type_id INT NOT NULL, max_production_limit INT NOT NULL, PRIMARY KEY (type_id))');
        $this->addSql('CREATE TABLE sde_inv_flags (flag_id INT NOT NULL, flag_name VARCHAR(100) DEFAULT NULL, flag_text VARCHAR(255) DEFAULT NULL, order_id INT NOT NULL, PRIMARY KEY (flag_id))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE sde_chr_factions');
        $this->addSql('DROP TABLE sde_chr_races');
        $this->addSql('DROP TABLE sde_dgm_attribute_types');
        $this->addSql('DROP TABLE sde_dgm_effects');
        $this->addSql('DROP TABLE sde_dgm_type_attributes');
        $this->addSql('DROP TABLE sde_dgm_type_effects');
        $this->addSql('DROP TABLE sde_eve_icons');
        $this->addSql('DROP TABLE sde_industry_activities');
        $this->addSql('DROP TABLE sde_industry_activity_materials');
        $this->addSql('DROP TABLE sde_industry_activity_products');
        $this->addSql('DROP TABLE sde_industry_activity_skills');
        $this->addSql('DROP TABLE sde_industry_blueprints');
        $this->addSql('DROP TABLE sde_inv_flags');
    }
}
