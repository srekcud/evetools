<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add in_stock_quantity field to industry_project_steps.
 * This replaces the boolean in_stock with a quantity-based approach
 * to properly handle shared components used by multiple parent products.
 */
final class Version20260202100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add in_stock_quantity to industry_project_steps for partial stock tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_steps ADD in_stock_quantity INT NOT NULL DEFAULT 0');
        // Migrate existing boolean values: if in_stock is true, set in_stock_quantity to the step quantity
        $this->addSql('UPDATE industry_project_steps SET in_stock_quantity = quantity WHERE in_stock = true');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_steps DROP COLUMN in_stock_quantity');
    }
}
