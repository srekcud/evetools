<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125230955 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the table of the buy orders of characters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cached_character_buy_orders (id UUID NOT NULL, order_id BIGINT NOT NULL, type_id INT NOT NULL, location_id BIGINT NOT NULL, price DOUBLE PRECISION NOT NULL, volume_remain INT NOT NULL, volume_total INT NOT NULL, issued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, synced_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, character_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5EA553171136BE75 ON cached_character_buy_orders (character_id)');
        $this->addSql('CREATE INDEX IDX_5EA55317C54C8C93 ON cached_character_buy_orders (type_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5EA553178D9F6D38 ON cached_character_buy_orders (order_id)');
        $this->addSql('ALTER TABLE cached_character_buy_orders ADD CONSTRAINT FK_5EA553171136BE75 FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_character_buy_orders DROP CONSTRAINT FK_5EA553171136BE75');
        $this->addSql('DROP TABLE cached_character_buy_orders');
    }
}
