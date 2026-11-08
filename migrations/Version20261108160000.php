<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the refinery follows and the moon chunk alerts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE moon_chunk_alerts (id UUID NOT NULL, kind VARCHAR(16) NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, extraction_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4FABC89BA76ED395 ON moon_chunk_alerts (user_id)');
        $this->addSql('CREATE INDEX IDX_4FABC89BF992488A ON moon_chunk_alerts (extraction_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_moon_chunk_alert_user_extraction_kind ON moon_chunk_alerts (user_id, extraction_id, kind)');
        $this->addSql('CREATE TABLE moon_refinery_follows (id UUID NOT NULL, corporation_id BIGINT NOT NULL, structure_id BIGINT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4354D2A1A76ED395 ON moon_refinery_follows (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_moon_refinery_follow_user_structure ON moon_refinery_follows (user_id, structure_id)');
        $this->addSql('ALTER TABLE moon_chunk_alerts ADD CONSTRAINT FK_4FABC89BA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE moon_chunk_alerts ADD CONSTRAINT FK_4FABC89BF992488A FOREIGN KEY (extraction_id) REFERENCES corporation_mining_extractions (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE moon_refinery_follows ADD CONSTRAINT FK_4354D2A1A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE moon_chunk_alerts DROP CONSTRAINT FK_4FABC89BA76ED395');
        $this->addSql('ALTER TABLE moon_chunk_alerts DROP CONSTRAINT FK_4FABC89BF992488A');
        $this->addSql('ALTER TABLE moon_refinery_follows DROP CONSTRAINT FK_4354D2A1A76ED395');
        $this->addSql('DROP TABLE moon_chunk_alerts');
        $this->addSql('DROP TABLE moon_refinery_follows');
    }
}
