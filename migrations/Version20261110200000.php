<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen the escalation price to bigint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE escalations ALTER price TYPE BIGINT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE escalations ALTER price TYPE INTEGER');
    }
}
