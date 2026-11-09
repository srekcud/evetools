<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the closing date of a mining site';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_sites ADD closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_sites DROP closed_at');
    }
}
