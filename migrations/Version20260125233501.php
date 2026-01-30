<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260125233501 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add loot_type_ids to user_pve_settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_pve_settings ADD loot_type_ids JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_pve_settings DROP loot_type_ids');
    }
}
