<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the personal use flag of an industry project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_projects ADD personal_use BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_projects DROP personal_use');
    }
}
