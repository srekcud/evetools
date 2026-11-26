<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the planetary home location and coverage target to users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD planetary_home_location_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD planetary_home_location_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD planetary_coverage_weeks SMALLINT DEFAULT 3 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP planetary_home_location_id');
        $this->addSql('ALTER TABLE users DROP planetary_home_location_name');
        $this->addSql('ALTER TABLE users DROP planetary_coverage_weeks');
    }
}
