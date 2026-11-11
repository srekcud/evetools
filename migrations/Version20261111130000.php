<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the price that triggered a market price alert';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE market_price_alerts ADD triggered_price DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE market_price_alerts DROP triggered_price');
    }
}
