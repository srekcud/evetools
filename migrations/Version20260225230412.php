<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260225230412 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE corp_asset_visibility (id UUID NOT NULL, corporation_id BIGINT NOT NULL, visible_divisions JSON NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, configured_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_38FBB8A1771AF0CB ON corp_asset_visibility (configured_by_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_38FBB8A1B2685369 ON corp_asset_visibility (corporation_id)');
        $this->addSql('ALTER TABLE corp_asset_visibility ADD CONSTRAINT FK_38FBB8A1771AF0CB FOREIGN KEY (configured_by_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE corp_asset_visibility DROP CONSTRAINT FK_38FBB8A1771AF0CB');
        $this->addSql('DROP TABLE corp_asset_visibility');
    }
}
