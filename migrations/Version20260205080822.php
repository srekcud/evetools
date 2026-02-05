<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260205080822 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create escalations table for DED escalation tracking';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE escalations (id UUID NOT NULL, character_id BIGINT NOT NULL, character_name VARCHAR(255) NOT NULL, type VARCHAR(100) NOT NULL, solar_system_id INT NOT NULL, solar_system_name VARCHAR(255) NOT NULL, security_status DOUBLE PRECISION NOT NULL, price INT NOT NULL, visibility VARCHAR(10) NOT NULL, bm_status VARCHAR(10) NOT NULL, sale_status VARCHAR(10) NOT NULL, notes TEXT DEFAULT NULL, corporation_id BIGINT NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9AEE4613A76ED395 ON escalations (user_id)');
        $this->addSql('CREATE INDEX IDX_9AEE4613A76ED395F9D83E2 ON escalations (user_id, expires_at)');
        $this->addSql('CREATE INDEX IDX_9AEE4613B2685369518E4300 ON escalations (corporation_id, visibility)');
        $this->addSql('CREATE INDEX IDX_9AEE4613518E4300F616BDEF9D83E2 ON escalations (visibility, sale_status, expires_at)');
        $this->addSql('ALTER TABLE escalations ADD CONSTRAINT FK_9AEE4613A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE escalations DROP CONSTRAINT FK_9AEE4613A76ED395');
        $this->addSql('DROP TABLE escalations');
    }
}
