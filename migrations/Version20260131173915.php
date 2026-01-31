<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260131173915 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_project_steps ADD similar_jobs JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE industry_structure_configs ALTER is_deleted DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_project_steps DROP similar_jobs');
        $this->addSql('ALTER TABLE industry_structure_configs ALTER is_deleted SET DEFAULT false');
    }
}
