<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the option of an industry project to accept a structure outside the favorite system and its minimum saving';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_projects ADD accepts_outside_favorite_system BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE industry_projects ADD minimum_outside_saving DOUBLE PRECISION DEFAULT \'500000\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_projects DROP accepts_outside_favorite_system');
        $this->addSql('ALTER TABLE industry_projects DROP minimum_outside_saving');
    }
}
