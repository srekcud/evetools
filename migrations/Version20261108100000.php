<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link an industry project to the template it was created from';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_projects ADD template_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_projects ADD CONSTRAINT FK_7CCBA9215DA0FB8 FOREIGN KEY (template_id) REFERENCES industry_project_templates (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_7CCBA9215DA0FB8 ON industry_projects (template_id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The links between the projects and their templates cannot be recreated once dropped.');
    }
}
