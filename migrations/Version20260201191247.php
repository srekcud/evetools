<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260201191247 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE shared_shopping_lists (id UUID NOT NULL, token VARCHAR(64) NOT NULL, data JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B8CF3DB85F37A13B ON shared_shopping_lists (token)');
        $this->addSql('CREATE INDEX IDX_B8CF3DB8B03A8386 ON shared_shopping_lists (created_by_id)');
        $this->addSql('CREATE INDEX idx_shared_shopping_list_expires ON shared_shopping_lists (expires_at)');
        $this->addSql('ALTER TABLE shared_shopping_lists ADD CONSTRAINT FK_B8CF3DB8B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE shared_shopping_lists DROP CONSTRAINT FK_B8CF3DB8B03A8386');
        $this->addSql('DROP TABLE shared_shopping_lists');
    }
}
