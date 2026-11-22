<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a player dedicate a character to an active project for job matching';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE characters ADD active_project_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE characters ADD CONSTRAINT FK_3A29410E37CF7D6A FOREIGN KEY (active_project_id) REFERENCES industry_projects (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_3A29410E37CF7D6A ON characters (active_project_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE characters DROP CONSTRAINT FK_3A29410E37CF7D6A');
        $this->addSql('DROP INDEX IDX_3A29410E37CF7D6A');
        $this->addSql('ALTER TABLE characters DROP COLUMN active_project_id');
    }
}
