<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260225173933 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE industry_scanner_favorites (id UUID NOT NULL, type_id INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2C315CC2A76ED395 ON industry_scanner_favorites (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2C315CC2A76ED395C54C8C93 ON industry_scanner_favorites (user_id, type_id)');
        $this->addSql('ALTER TABLE industry_scanner_favorites ADD CONSTRAINT FK_2C315CC2A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_scanner_favorites DROP CONSTRAINT FK_2C315CC2A76ED395');
        $this->addSql('DROP TABLE industry_scanner_favorites');
    }
}
