<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the per-division player lists to the visibility of the corporation hangars';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE corp_asset_visibility ADD restricted_divisions JSON DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE corp_asset_visibility DROP restricted_divisions');
    }
}
