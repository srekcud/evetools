<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track the imported SDE build, the latest CCP build and the last build notified to admins';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sde_build_state (id INT NOT NULL, imported_build_number INT DEFAULT NULL, imported_release_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, imported_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, latest_build_number INT DEFAULT NULL, latest_release_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, latest_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, notified_build_number INT DEFAULT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sde_build_state');
    }
}
