<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give every line reservation an expiry, 48 hours of grace for the existing ones';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_line_reservations ADD expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("UPDATE industry_project_line_reservations SET expires_at = NOW() + INTERVAL '48 hours'");
        $this->addSql('ALTER TABLE industry_project_line_reservations ALTER COLUMN expires_at SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_line_reservations DROP COLUMN expires_at');
    }
}
