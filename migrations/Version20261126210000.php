<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the planetary colony layouts imported by users from game templates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE planetary_colony_layouts (id UUID NOT NULL, name VARCHAR(100) NOT NULL, template JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F37F2EAAA76ED395 ON planetary_colony_layouts (user_id)');
        $this->addSql('CREATE INDEX IDX_F37F2EAAA76ED3958B8E8428 ON planetary_colony_layouts (user_id, created_at)');
        $this->addSql('ALTER TABLE planetary_colony_layouts ADD CONSTRAINT FK_F37F2EAAA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planetary_colony_layouts DROP CONSTRAINT FK_F37F2EAAA76ED395');
        $this->addSql('DROP TABLE planetary_colony_layouts');
    }
}
