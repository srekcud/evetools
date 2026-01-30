<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260128081848 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_project_steps ALTER activity_type DROP DEFAULT');
        $this->addSql('ALTER TABLE industry_project_steps ALTER sort_order DROP DEFAULT');
        $this->addSql('ALTER TABLE industry_projects ADD excluded_type_ids JSON NOT NULL DEFAULT \'[]\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_project_steps ALTER activity_type SET DEFAULT \'manufacturing\'');
        $this->addSql('ALTER TABLE industry_project_steps ALTER sort_order SET DEFAULT 0');
        $this->addSql('ALTER TABLE industry_projects DROP excluded_type_ids');
    }
}
