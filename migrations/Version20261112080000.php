<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record each run of a tracked sync type';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sync_runs (id UUID NOT NULL, type VARCHAR(60) NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, status VARCHAR(10) NOT NULL, message VARCHAR(500) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D2ECD7508CDE5729D46F4E3 ON sync_runs (type, started_at)');
        $this->addSql('CREATE INDEX IDX_D2ECD750D46F4E3 ON sync_runs (started_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sync_runs');
    }
}
