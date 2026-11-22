<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a player dismiss an unassigned job as not meant for a project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_unassigned_jobs ADD dismissed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_unassigned_jobs DROP COLUMN dismissed_at');
    }
}
