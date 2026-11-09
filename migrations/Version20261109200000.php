<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give each industry project sale a sales channel, market or contract, defaulting existing sales to market';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE industry_project_sales ADD channel VARCHAR(20) DEFAULT 'market' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_sales DROP channel');
    }
}
