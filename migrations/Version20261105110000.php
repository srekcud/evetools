<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create structure_traded_volumes table for the estimated traded volume of a structure';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE structure_traded_volumes (id UUID NOT NULL, structure_id BIGINT NOT NULL, type_id INT NOT NULL, date DATE NOT NULL, traded_volume BIGINT DEFAULT 0 NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_859935F2534008BC54C8C93 ON structure_traded_volumes (structure_id, type_id)');
        $this->addSql('CREATE INDEX IDX_859935FAA9E377A ON structure_traded_volumes (date)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_859935F2534008BC54C8C93AA9E377A ON structure_traded_volumes (structure_id, type_id, date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE structure_traded_volumes');
    }
}
