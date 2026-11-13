<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move the container name of an industry project into a child table so a project can watch several containers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE industry_project_containers (id UUID NOT NULL, name VARCHAR(255) NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F9B6047C166D1F9C ON industry_project_containers (project_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F9B6047C166D1F9C5E237E06 ON industry_project_containers (project_id, name)');
        $this->addSql('ALTER TABLE industry_project_containers ADD CONSTRAINT FK_F9B6047C166D1F9C FOREIGN KEY (project_id) REFERENCES industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("INSERT INTO industry_project_containers (id, project_id, name) SELECT gen_random_uuid(), id, BTRIM(container_name) FROM industry_projects WHERE container_name IS NOT NULL AND BTRIM(container_name) <> ''");
        $this->addSql('ALTER TABLE industry_projects DROP container_name');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_projects ADD container_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('UPDATE industry_projects SET container_name = oldest.name FROM (SELECT project_id, MIN(name) AS name FROM industry_project_containers GROUP BY project_id) AS oldest WHERE oldest.project_id = industry_projects.id');
        $this->addSql('DROP TABLE industry_project_containers');
    }
}
