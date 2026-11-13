<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the option to exclude invention from an industry project, its template and the user industry settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_projects ADD excludes_invention BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE industry_project_templates ADD excludes_invention BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE users ADD industry_exclude_invention BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_projects DROP excludes_invention');
        $this->addSql('ALTER TABLE industry_project_templates DROP excludes_invention');
        $this->addSql('ALTER TABLE users DROP industry_exclude_invention');
    }
}
