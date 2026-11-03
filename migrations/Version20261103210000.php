<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the last activity time of the participants of a mining operation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_operation_members ADD last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_operation_members DROP last_seen_at');
    }
}
