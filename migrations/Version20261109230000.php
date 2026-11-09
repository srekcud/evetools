<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the blueprint and output locations of the cached industry jobs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_industry_jobs ADD blueprint_location_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE cached_industry_jobs ADD output_location_id BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_industry_jobs DROP blueprint_location_id');
        $this->addSql('ALTER TABLE cached_industry_jobs DROP output_location_id');
    }
}
