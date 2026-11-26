<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126014825 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mark the plan template a user follows as a target';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planetary_plan_templates ADD followed BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planetary_plan_templates DROP followed');
    }
}
