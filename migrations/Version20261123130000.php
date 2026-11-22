<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the structure access of each character, from ESI or from its past industry jobs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE character_structure_access (id UUID NOT NULL, character_id UUID NOT NULL, structure_id BIGINT NOT NULL, status VARCHAR(16) NOT NULL, source VARCHAR(16) NOT NULL, checked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_A9FDCC7A1136BE75 ON character_structure_access (character_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A9FDCC7A1136BE752534008B ON character_structure_access (character_id, structure_id)');
        $this->addSql('ALTER TABLE character_structure_access ADD CONSTRAINT FK_STRUCTURE_ACCESS_CHARACTER FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE character_structure_access');
    }
}
