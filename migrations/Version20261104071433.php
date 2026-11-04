<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104071433 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create mining sites and their scans';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mining_site_scans (id UUID NOT NULL, scanned_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, total_units BIGINT NOT NULL, total_volume_m3 DOUBLE PRECISION DEFAULT NULL, remaining_value DOUBLE PRECISION DEFAULT NULL, missing_price_type_ids JSON NOT NULL, recent_eta TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, trend_eta TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, types JSON NOT NULL, site_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8FA59BA8F6BD1646 ON mining_site_scans (site_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8FA59BA8F6BD1646C7D40C1C ON mining_site_scans (site_id, scanned_at)');
        $this->addSql('CREATE TABLE mining_sites (id UUID NOT NULL, label VARCHAR(100) NOT NULL, solar_system_id INT DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D5D527B67E3C61F9 ON mining_sites (owner_id)');
        $this->addSql('ALTER TABLE mining_site_scans ADD CONSTRAINT FK_8FA59BA8F6BD1646 FOREIGN KEY (site_id) REFERENCES mining_sites (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_sites ADD CONSTRAINT FK_D5D527B67E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_site_scans DROP CONSTRAINT FK_8FA59BA8F6BD1646');
        $this->addSql('ALTER TABLE mining_sites DROP CONSTRAINT FK_D5D527B67E3C61F9');
        $this->addSql('DROP TABLE mining_site_scans');
        $this->addSql('DROP TABLE mining_sites');
    }
}
