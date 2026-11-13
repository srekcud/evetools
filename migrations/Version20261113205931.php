<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113205931 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record when the wallet of each character was last read and when ESI allows the next read';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE character_wallet_reading (id UUID NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, next_read_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, character_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_99F6A1721136BE75 ON character_wallet_reading (character_id)');
        $this->addSql('ALTER TABLE character_wallet_reading ADD CONSTRAINT FK_99F6A1721136BE75 FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_wallet_reading DROP CONSTRAINT FK_99F6A1721136BE75');
        $this->addSql('DROP TABLE character_wallet_reading');
    }
}
