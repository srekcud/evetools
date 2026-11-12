<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track the last resolution attempt of cached structures';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_structure ADD last_resolution_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_structure DROP last_resolution_attempt_at');
    }
}
