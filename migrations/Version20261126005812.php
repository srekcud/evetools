<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126005812 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the planetary plan templates saved by the PI planner';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE planetary_plan_templates (id UUID NOT NULL, name VARCHAR(100) NOT NULL, productions JSON NOT NULL, choices JSON NOT NULL, settings JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_1D0D3875A76ED395 ON planetary_plan_templates (user_id)');
        $this->addSql('CREATE INDEX IDX_1D0D3875A76ED3958B8E8428 ON planetary_plan_templates (user_id, created_at)');
        $this->addSql('ALTER TABLE planetary_plan_templates ADD CONSTRAINT FK_1D0D3875A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planetary_plan_templates DROP CONSTRAINT FK_1D0D3875A76ED395');
        $this->addSql('DROP TABLE planetary_plan_templates');
    }
}
