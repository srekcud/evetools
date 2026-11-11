<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111004055 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the table of the sell orders of characters compared to the best sell price';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cached_character_orders (id UUID NOT NULL, order_id BIGINT NOT NULL, type_id INT NOT NULL, location_id BIGINT NOT NULL, price DOUBLE PRECISION NOT NULL, volume_remain INT NOT NULL, volume_total INT NOT NULL, issued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, synced_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, state VARCHAR(10) NOT NULL, rank INT DEFAULT NULL, best_price DOUBLE PRECISION DEFAULT NULL, unknown_reason VARCHAR(30) DEFAULT NULL, suggested_price DOUBLE PRECISION DEFAULT NULL, undercut_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, character_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_635DA3D41136BE75 ON cached_character_orders (character_id)');
        $this->addSql('CREATE INDEX IDX_635DA3D4C54C8C93 ON cached_character_orders (type_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_635DA3D48D9F6D38 ON cached_character_orders (order_id)');
        $this->addSql('ALTER TABLE cached_character_orders ADD CONSTRAINT FK_635DA3D41136BE75 FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_character_orders DROP CONSTRAINT FK_635DA3D41136BE75');
        $this->addSql('DROP TABLE cached_character_orders');
    }
}
