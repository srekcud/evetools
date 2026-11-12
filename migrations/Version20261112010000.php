<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the columns written but never read: cached_structure.resolved_at, cached_industry_jobs.completed_date, cached_wallet_transactions.client_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_structure DROP resolved_at');
        $this->addSql('ALTER TABLE cached_industry_jobs DROP completed_date');
        $this->addSql('ALTER TABLE cached_wallet_transactions DROP client_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_structure ADD resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NOW() NOT NULL');
        $this->addSql('ALTER TABLE cached_structure ALTER resolved_at DROP DEFAULT');
        $this->addSql('ALTER TABLE cached_industry_jobs ADD completed_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE cached_wallet_transactions ADD client_id BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE cached_wallet_transactions ALTER client_id DROP DEFAULT');
    }
}
