<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reserve the completion notification of an industry job once, existing finished jobs counted as notified';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_industry_jobs ADD completion_notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("UPDATE cached_industry_jobs SET completion_notified_at = cached_at WHERE status IN ('ready', 'delivered', 'cancelled', 'reverted')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_industry_jobs DROP completion_notified_at');
    }
}
