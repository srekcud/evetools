<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126090331 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the encrypted Discord webhook URL of each user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_discord_webhooks (id UUID NOT NULL, url_encrypted TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_18641858A76ED395 ON user_discord_webhooks (user_id)');
        $this->addSql('ALTER TABLE user_discord_webhooks ADD CONSTRAINT FK_18641858A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_discord_webhooks DROP CONSTRAINT FK_18641858A76ED395');
        $this->addSql('DROP TABLE user_discord_webhooks');
    }
}
