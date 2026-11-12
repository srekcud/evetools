<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the ESI singleton flag on cached assets';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_assets ADD is_singleton BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_assets DROP is_singleton');
    }
}
