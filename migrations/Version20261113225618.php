<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113225618 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the changes between the last two asset syncs of each character and corporation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE asset_sync_changes (id UUID NOT NULL, corporation_id BIGINT DEFAULT NULL, type_id INT NOT NULL, type_name VARCHAR(255) DEFAULT NULL, location_id BIGINT NOT NULL, location_name VARCHAR(255) NOT NULL, division_name VARCHAR(255) DEFAULT NULL, quantity_delta INT NOT NULL, synced_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, previous_synced_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, character_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4FB3366B1136BE75 ON asset_sync_changes (character_id)');
        $this->addSql('CREATE INDEX IDX_4FB3366BB2685369 ON asset_sync_changes (corporation_id)');
        $this->addSql('ALTER TABLE asset_sync_changes ADD CONSTRAINT FK_4FB3366B1136BE75 FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset_sync_changes DROP CONSTRAINT FK_4FB3366B1136BE75');
        $this->addSql('DROP TABLE asset_sync_changes');
    }
}
