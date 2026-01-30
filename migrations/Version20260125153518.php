<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260125153518 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add solar system jumps table for pathfinding';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sde_map_solar_system_jumps (from_solar_system_id INT NOT NULL, to_solar_system_id INT NOT NULL, from_region_id INT NOT NULL, from_constellation_id INT NOT NULL, to_region_id INT NOT NULL, to_constellation_id INT NOT NULL, PRIMARY KEY (from_solar_system_id, to_solar_system_id))');
        $this->addSql('CREATE INDEX IDX_868586AAACCFA3CC ON sde_map_solar_system_jumps (from_solar_system_id)');
        $this->addSql('CREATE INDEX IDX_868586AA641482B2 ON sde_map_solar_system_jumps (to_solar_system_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE sde_map_solar_system_jumps');
    }
}
