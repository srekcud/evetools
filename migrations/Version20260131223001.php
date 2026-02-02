<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260131223001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add te_level field to industry_projects table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_projects ADD te_level INT DEFAULT 20 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_projects DROP te_level');
    }
}
