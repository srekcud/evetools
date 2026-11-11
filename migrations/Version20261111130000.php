<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the bridge acknowledgements table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_acknowledgements (id UUID NOT NULL, item_key VARCHAR(64) NOT NULL, acknowledged_count INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_73E1733A76ED395 ON bridge_acknowledgements (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_73E1733A76ED3956D85D09B ON bridge_acknowledgements (user_id, item_key)');
        $this->addSql('ALTER TABLE bridge_acknowledgements ADD CONSTRAINT FK_73E1733A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_acknowledgements DROP CONSTRAINT FK_73E1733A76ED395');
        $this->addSql('DROP TABLE bridge_acknowledgements');
    }
}
