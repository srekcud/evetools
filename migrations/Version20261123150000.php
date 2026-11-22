<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow the same container name at several stations of an industry project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_F9B6047C166D1F9C5E237E06');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F9B6047C166D1F9C5E237E06 ON industry_project_containers (project_id, name)');
    }
}
