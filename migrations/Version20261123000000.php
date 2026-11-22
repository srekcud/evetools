<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the personal stock containers a user declares in corporation divisions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE personal_stock_containers (id UUID NOT NULL, item_id BIGINT NOT NULL, name VARCHAR(255) NOT NULL, division_name VARCHAR(255) DEFAULT NULL, station_id BIGINT DEFAULT NULL, station_name VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B2303596A76ED395 ON personal_stock_containers (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B2303596126F525E ON personal_stock_containers (item_id)');
        $this->addSql('ALTER TABLE personal_stock_containers ADD CONSTRAINT FK_B2303596A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE personal_stock_containers');
    }
}
