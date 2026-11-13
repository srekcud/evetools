<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the SDE planets and moons tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sde_map_moons (moon_id INT NOT NULL, solar_system_id INT NOT NULL, orbit_id INT NOT NULL, celestial_index INT NOT NULL, orbit_index INT NOT NULL, PRIMARY KEY (moon_id))');
        $this->addSql('CREATE INDEX IDX_9941F4C4E5C8C6D3 ON sde_map_moons (solar_system_id)');
        $this->addSql('CREATE TABLE sde_map_planets (planet_id INT NOT NULL, solar_system_id INT NOT NULL, celestial_index INT NOT NULL, type_id INT NOT NULL, PRIMARY KEY (planet_id))');
        $this->addSql('CREATE INDEX IDX_F1C6DD86E5C8C6D3 ON sde_map_planets (solar_system_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sde_map_moons');
        $this->addSql('DROP TABLE sde_map_planets');
    }
}
