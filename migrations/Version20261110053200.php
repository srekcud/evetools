<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110053200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the mining valuation profile of a user and remember the valuation source of each mining site scan';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE user_mining_valuation_profiles (id UUID NOT NULL, market VARCHAR(20) DEFAULT 'jita' NOT NULL, side VARCHAR(10) DEFAULT 'buy' NOT NULL, form VARCHAR(20) DEFAULT 'compressed' NOT NULL, structure_id BIGINT DEFAULT NULL, structure_name VARCHAR(255) DEFAULT NULL, export_tax DOUBLE PRECISION DEFAULT 0 NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7B9C8702A76ED395 ON user_mining_valuation_profiles (user_id)');
        $this->addSql('ALTER TABLE user_mining_valuation_profiles ADD CONSTRAINT FK_7B9C8702A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mining_site_scans ADD valuation_source JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_site_scans DROP valuation_source');
        $this->addSql('DROP TABLE user_mining_valuation_profiles');
    }
}
