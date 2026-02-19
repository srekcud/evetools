<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260218131421 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add probability column to SDE industry activity products';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sde_industry_activity_products ADD probability DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sde_industry_activity_products DROP probability');
    }
}
