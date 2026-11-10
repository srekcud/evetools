<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the depletion and downtime alerts sent for a mining site';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_sites ADD depletion_alerted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_sites ADD downtime_alerted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_sites DROP depletion_alerted_at');
        $this->addSql('ALTER TABLE mining_sites DROP downtime_alerted_at');
    }
}
