<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cache the base attributes of each character';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cached_character_attributes (id UUID NOT NULL, intelligence INT NOT NULL, memory INT NOT NULL, perception INT NOT NULL, willpower INT NOT NULL, charisma INT NOT NULL, cached_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, character_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D1FDB86E1136BE75 ON cached_character_attributes (character_id)');
        $this->addSql('ALTER TABLE cached_character_attributes ADD CONSTRAINT FK_D1FDB86E1136BE75 FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_character_attributes DROP CONSTRAINT FK_D1FDB86E1136BE75');
        $this->addSql('DROP TABLE cached_character_attributes');
    }
}
