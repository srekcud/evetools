<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260125203415 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pve_expenses (id UUID NOT NULL, type VARCHAR(50) NOT NULL, description VARCHAR(255) NOT NULL, amount DOUBLE PRECISION NOT NULL, date DATE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9299B28AA76ED395 ON pve_expenses (user_id)');
        $this->addSql('CREATE INDEX IDX_9299B28AA76ED395AA9E377A ON pve_expenses (user_id, date)');
        $this->addSql('ALTER TABLE pve_expenses ADD CONSTRAINT FK_9299B28AA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pve_expenses DROP CONSTRAINT FK_9299B28AA76ED395');
        $this->addSql('DROP TABLE pve_expenses');
    }
}
