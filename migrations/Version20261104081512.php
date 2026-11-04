<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104081512 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the moon compositions recorded per corporation and moon';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE moon_compositions (id UUID NOT NULL, corporation_id BIGINT NOT NULL, moon_id BIGINT NOT NULL, recorded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, lines JSON NOT NULL, recorded_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E87AF39BD05A957B ON moon_compositions (recorded_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_moon_composition_corporation_moon ON moon_compositions (corporation_id, moon_id)');
        $this->addSql('ALTER TABLE moon_compositions ADD CONSTRAINT FK_E87AF39BD05A957B FOREIGN KEY (recorded_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE moon_compositions DROP CONSTRAINT FK_E87AF39BD05A957B');
        $this->addSql('DROP TABLE moon_compositions');
    }
}
