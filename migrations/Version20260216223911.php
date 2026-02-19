<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260216223911 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create structure_market_snapshots table for structure price history';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE structure_market_snapshots (id UUID NOT NULL, structure_id BIGINT NOT NULL, type_id INT NOT NULL, date DATE NOT NULL, sell_min DOUBLE PRECISION DEFAULT NULL, buy_max DOUBLE PRECISION DEFAULT NULL, sell_order_count INT DEFAULT 0 NOT NULL, buy_order_count INT DEFAULT 0 NOT NULL, sell_volume BIGINT DEFAULT 0 NOT NULL, buy_volume BIGINT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E514D6002534008BC54C8C93 ON structure_market_snapshots (structure_id, type_id)');
        $this->addSql('CREATE INDEX IDX_E514D600AA9E377A ON structure_market_snapshots (date)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E514D6002534008BC54C8C93AA9E377A ON structure_market_snapshots (structure_id, type_id, date)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE structure_market_snapshots');
    }
}
