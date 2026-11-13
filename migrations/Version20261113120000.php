<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the market structure on structure price alerts, defaulting existing structure alerts to the preferred market structure of their owner';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE market_price_alerts ADD structure_id BIGINT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE market_price_alerts
            SET structure_id = users.preferred_market_structure_id
            FROM users
            WHERE users.id = market_price_alerts.user_id
              AND market_price_alerts.price_source IN ('structure_sell', 'structure_buy')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE market_price_alerts DROP structure_id');
    }
}
