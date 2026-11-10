<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remember the item id of the blueprint of each cached industry job';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_industry_jobs ADD blueprint_id BIGINT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_6C79ED9240E556F5 ON cached_industry_jobs (blueprint_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_6C79ED9240E556F5');
        $this->addSql('ALTER TABLE cached_industry_jobs DROP blueprint_id');
    }
}
