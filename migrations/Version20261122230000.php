<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the location of the project containers, one container per station of a project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_containers ADD location_id BIGINT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F9B6047C166D1F9C64D218E ON industry_project_containers (project_id, location_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_F9B6047C166D1F9C64D218E');
        $this->addSql('ALTER TABLE industry_project_containers DROP location_id');
    }
}
