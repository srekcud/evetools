<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Share the corporation blueprints of hidden divisions by default';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE corp_asset_visibility ADD share_blueprints_of_hidden_divisions BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE corp_asset_visibility DROP share_blueprints_of_hidden_divisions');
    }
}
