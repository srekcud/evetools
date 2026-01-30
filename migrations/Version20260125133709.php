<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260125133709 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cached_assets (id UUID NOT NULL, item_id BIGINT NOT NULL, type_id INT NOT NULL, type_name VARCHAR(255) NOT NULL, quantity INT NOT NULL, location_id BIGINT NOT NULL, location_name VARCHAR(255) NOT NULL, location_type VARCHAR(50) NOT NULL, location_flag VARCHAR(50) DEFAULT NULL, division_name VARCHAR(255) DEFAULT NULL, corporation_id BIGINT DEFAULT NULL, is_corporation_asset BOOLEAN NOT NULL, cached_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, character_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5B674B111136BE75 ON cached_assets (character_id)');
        $this->addSql('CREATE INDEX IDX_5B674B11B2685369 ON cached_assets (corporation_id)');
        $this->addSql('CREATE INDEX IDX_5B674B1164D218E ON cached_assets (location_id)');
        $this->addSql('CREATE INDEX IDX_5B674B11C54C8C93 ON cached_assets (type_id)');
        $this->addSql('CREATE TABLE characters (id UUID NOT NULL, eve_character_id BIGINT NOT NULL, name VARCHAR(255) NOT NULL, corporation_id BIGINT NOT NULL, corporation_name VARCHAR(255) NOT NULL, alliance_id BIGINT DEFAULT NULL, alliance_name VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_sync_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3A29410EA76ED395 ON characters (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3A29410EC7626E80 ON characters (eve_character_id)');
        $this->addSql('CREATE TABLE eve_tokens (id UUID NOT NULL, access_token TEXT NOT NULL, refresh_token_encrypted TEXT NOT NULL, access_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, scopes JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, character_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1F39AB11136BE75 ON eve_tokens (character_id)');
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, auth_status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, main_character_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E988E0BCC ON users (main_character_id)');
        $this->addSql('ALTER TABLE cached_assets ADD CONSTRAINT FK_5B674B111136BE75 FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE characters ADD CONSTRAINT FK_3A29410EA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE eve_tokens ADD CONSTRAINT FK_1F39AB11136BE75 FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E988E0BCC FOREIGN KEY (main_character_id) REFERENCES characters (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cached_assets DROP CONSTRAINT FK_5B674B111136BE75');
        $this->addSql('ALTER TABLE characters DROP CONSTRAINT FK_3A29410EA76ED395');
        $this->addSql('ALTER TABLE eve_tokens DROP CONSTRAINT FK_1F39AB11136BE75');
        $this->addSql('ALTER TABLE users DROP CONSTRAINT FK_1483A5E988E0BCC');
        $this->addSql('DROP TABLE cached_assets');
        $this->addSql('DROP TABLE characters');
        $this->addSql('DROP TABLE eve_tokens');
        $this->addSql('DROP TABLE users');
    }
}
