<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the authorization status to characters, every character starting valid';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE characters ADD auth_status VARCHAR(20) DEFAULT 'valid' NOT NULL");
        $this->addSql('ALTER TABLE characters ALTER auth_status DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE characters DROP auth_status');
    }
}
