<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113234406 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the packaged volume of each item type, refreshed weekly from ESI';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE type_packaged_volumes (type_id INT NOT NULL, packaged_volume DOUBLE PRECISION NOT NULL, refreshed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (type_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE type_packaged_volumes');
    }
}
