<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the declared T2 blueprint copies of an industry project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE industry_project_declared_blueprint_copies (id UUID NOT NULL, product_type_id INT NOT NULL, runs INT NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2AB544B9166D1F9C ON industry_project_declared_blueprint_copies (project_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2AB544B9166D1F9C14959723 ON industry_project_declared_blueprint_copies (project_id, product_type_id)');
        $this->addSql('ALTER TABLE industry_project_declared_blueprint_copies ADD CONSTRAINT FK_2AB544B9166D1F9C FOREIGN KEY (project_id) REFERENCES industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_declared_blueprint_copies DROP CONSTRAINT FK_2AB544B9166D1F9C');
        $this->addSql('DROP TABLE industry_project_declared_blueprint_copies');
    }
}
