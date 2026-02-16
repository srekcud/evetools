<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260216083622 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE market_favorites (id UUID NOT NULL, type_id INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_713B9B62A76ED395 ON market_favorites (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_713B9B62A76ED395C54C8C93 ON market_favorites (user_id, type_id)');
        $this->addSql('CREATE TABLE market_price_alerts (id UUID NOT NULL, type_id INT NOT NULL, type_name VARCHAR(255) NOT NULL, direction VARCHAR(10) NOT NULL, threshold DOUBLE PRECISION NOT NULL, price_source VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, triggered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3092CE75A76ED395 ON market_price_alerts (user_id)');
        $this->addSql('CREATE INDEX IDX_3092CE757B00651C ON market_price_alerts (status)');
        $this->addSql('CREATE TABLE market_price_history (id UUID NOT NULL, type_id INT NOT NULL, region_id INT NOT NULL, date DATE NOT NULL, average DOUBLE PRECISION NOT NULL, highest DOUBLE PRECISION NOT NULL, lowest DOUBLE PRECISION NOT NULL, order_count INT NOT NULL, volume BIGINT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_DD72A526AA9E377A ON market_price_history (date)');
        $this->addSql('CREATE INDEX IDX_DD72A526C54C8C93 ON market_price_history (type_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DD72A526C54C8C9398260155AA9E377A ON market_price_history (type_id, region_id, date)');
        $this->addSql('CREATE TABLE notifications (id UUID NOT NULL, category VARCHAR(30) NOT NULL, level VARCHAR(10) NOT NULL, title VARCHAR(255) NOT NULL, message TEXT NOT NULL, data JSON DEFAULT NULL, route VARCHAR(255) DEFAULT NULL, is_read BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6000B0D3A76ED395 ON notifications (user_id)');
        $this->addSql('CREATE INDEX IDX_6000B0D3A76ED395DA46F468B8E8428 ON notifications (user_id, is_read, created_at)');
        $this->addSql('CREATE INDEX IDX_6000B0D3A76ED39564C19C18B8E8428 ON notifications (user_id, category, created_at)');
        $this->addSql('CREATE TABLE push_subscriptions (id UUID NOT NULL, endpoint TEXT NOT NULL, public_key VARCHAR(255) NOT NULL, auth_token VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3FEC449DA76ED395 ON push_subscriptions (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3FEC449DA76ED395C4420F7B ON push_subscriptions (user_id, endpoint)');
        $this->addSql('CREATE TABLE user_notification_preferences (id UUID NOT NULL, category VARCHAR(30) NOT NULL, enabled BOOLEAN NOT NULL, threshold_minutes INT DEFAULT NULL, push_enabled BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_207F257FA76ED395 ON user_notification_preferences (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_207F257FA76ED39564C19C1 ON user_notification_preferences (user_id, category)');
        $this->addSql('ALTER TABLE market_favorites ADD CONSTRAINT FK_713B9B62A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE market_price_alerts ADD CONSTRAINT FK_3092CE75A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D3A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE push_subscriptions ADD CONSTRAINT FK_3FEC449DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_notification_preferences ADD CONSTRAINT FK_207F257FA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE market_favorites DROP CONSTRAINT FK_713B9B62A76ED395');
        $this->addSql('ALTER TABLE market_price_alerts DROP CONSTRAINT FK_3092CE75A76ED395');
        $this->addSql('ALTER TABLE notifications DROP CONSTRAINT FK_6000B0D3A76ED395');
        $this->addSql('ALTER TABLE push_subscriptions DROP CONSTRAINT FK_3FEC449DA76ED395');
        $this->addSql('ALTER TABLE user_notification_preferences DROP CONSTRAINT FK_207F257FA76ED395');
        $this->addSql('DROP TABLE market_favorites');
        $this->addSql('DROP TABLE market_price_alerts');
        $this->addSql('DROP TABLE market_price_history');
        $this->addSql('DROP TABLE notifications');
        $this->addSql('DROP TABLE push_subscriptions');
        $this->addSql('DROP TABLE user_notification_preferences');
    }
}
