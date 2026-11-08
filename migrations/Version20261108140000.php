<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the hiding instant to the scans of a mining site';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_site_scans ADD hidden_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_site_scans DROP hidden_at');
    }
}
