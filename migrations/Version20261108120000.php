<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record when the failure of a linked courier contract was notified to the project owner';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_courier_contracts ADD failure_notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_courier_contracts DROP failure_notified_at');
    }
}
