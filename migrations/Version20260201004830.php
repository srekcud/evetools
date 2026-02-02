<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create industry_rig_definitions table to store rig ME and TE bonuses from SDE.
 */
final class Version20260201004830 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create industry_rig_definitions table for rig ME/TE bonuses';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE industry_rig_definitions (
                id SERIAL PRIMARY KEY,
                rig_name VARCHAR(255) NOT NULL,
                rig_type_id INTEGER NOT NULL,
                material_bonus DOUBLE PRECISION NOT NULL DEFAULT 0,
                time_bonus DOUBLE PRECISION NOT NULL DEFAULT 0,
                target_categories JSON NOT NULL DEFAULT \'[]\',
                is_reaction BOOLEAN NOT NULL DEFAULT false,
                UNIQUE(rig_type_id)
            )
        ');

        $this->addSql('CREATE INDEX idx_rig_definitions_type_id ON industry_rig_definitions(rig_type_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS industry_rig_definitions');
    }
}
