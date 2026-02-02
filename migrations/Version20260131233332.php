<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260131233332 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add in_stock field to industry_project_steps to distinguish purchased vs already owned items';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_project_steps ADD in_stock BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE industry_projects ALTER te_level DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_project_steps DROP in_stock');
        $this->addSql('ALTER TABLE industry_projects ALTER te_level SET DEFAULT 20');
    }
}
