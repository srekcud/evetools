<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the NPC standings of each character and the time they were last synchronized';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cached_character_standings (id UUID NOT NULL, character_id UUID NOT NULL, from_id INT NOT NULL, from_type VARCHAR(20) NOT NULL, standing DOUBLE PRECISION NOT NULL, cached_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_20A80F6D1136BE75 ON cached_character_standings (character_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_20A80F6D1136BE75F9488ACA78CED90B ON cached_character_standings (character_id, from_type, from_id)');
        $this->addSql('ALTER TABLE cached_character_standings ADD CONSTRAINT FK_CACHED_CHARACTER_STANDINGS_CHARACTER FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE characters ADD standings_synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE characters DROP standings_synced_at');
        $this->addSql('DROP TABLE cached_character_standings');
    }
}
