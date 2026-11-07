<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the industry project templates, personal or shared with the corporation of their owner';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE industry_project_templates (
              id UUID NOT NULL,
              name VARCHAR(255) NOT NULL,
              items JSON NOT NULL,
              blacklist_group_ids JSON NOT NULL,
              blacklist_type_ids JSON NOT NULL,
              accepts_outside_favorite_system BOOLEAN DEFAULT false NOT NULL,
              minimum_outside_saving DOUBLE PRECISION NOT NULL,
              shared_with_corporation BOOLEAN DEFAULT false NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              owner_id UUID NOT NULL,
              PRIMARY KEY (id),
              CONSTRAINT FK_A83BF25C7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_A83BF25C7E3C61F9 ON industry_project_templates (owner_id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The project templates saved by the users cannot be recreated once dropped.');
    }
}
