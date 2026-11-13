<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113223404 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the corporation containers each user pins in the inventory';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pinned_containers (id UUID NOT NULL, item_id BIGINT NOT NULL, name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_82E00DA76ED395 ON pinned_containers (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_82E00DA76ED395126F525E ON pinned_containers (user_id, item_id)');
        $this->addSql('ALTER TABLE pinned_containers ADD CONSTRAINT FK_82E00DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pinned_containers DROP CONSTRAINT FK_82E00DA76ED395');
        $this->addSql('DROP TABLE pinned_containers');
    }
}
