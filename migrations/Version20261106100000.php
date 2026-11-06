<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make the estimated value of an industry project contribution nullable (unknown price)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_contributions ALTER estimated_value DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('A contribution without a known value cannot be given a value back.');
    }
}
