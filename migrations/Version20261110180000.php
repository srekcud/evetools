<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the moment the fleet hangar of a mining operation was last declared emptied';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_operations ADD fleet_hangar_emptied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_operations DROP fleet_hangar_emptied_at');
    }
}
