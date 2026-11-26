<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the publication state to the planetary colony layouts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planetary_colony_layouts ADD public BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE planetary_colony_layouts ADD published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planetary_colony_layouts DROP public');
        $this->addSql('ALTER TABLE planetary_colony_layouts DROP published_at');
    }
}
