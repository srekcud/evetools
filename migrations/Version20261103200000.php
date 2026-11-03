<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete every existing solo and group industry project with its steps, job matches, purchases, members, items, bill of materials, contributions and sales';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM industry_projects');
        $this->addSql('DELETE FROM group_industry_projects');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The deleted industry projects cannot be restored.');
    }
}
