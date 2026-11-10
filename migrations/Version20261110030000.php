<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remember the last notified expiry level of a planetary pin and move the planetary threshold default from 120 to 1440 minutes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planetary_pins ADD expiry_notified_level VARCHAR(10) DEFAULT NULL');
        $this->addSql("UPDATE user_notification_preferences SET threshold_minutes = 1440 WHERE category = 'planetary' AND threshold_minutes = 120");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planetary_pins DROP expiry_notified_level');
    }
}
