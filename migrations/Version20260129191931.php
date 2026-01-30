<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260129191931 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE industry_structure_configs (id UUID NOT NULL, name VARCHAR(255) NOT NULL, security_type VARCHAR(20) NOT NULL, structure_type VARCHAR(50) NOT NULL, rigs JSON NOT NULL, is_default BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4FE4C207A76ED395 ON industry_structure_configs (user_id)');
        $this->addSql('ALTER TABLE industry_structure_configs ADD CONSTRAINT FK_4FE4C207A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_structure_configs DROP CONSTRAINT FK_4FE4C207A76ED395');
        $this->addSql('DROP TABLE industry_structure_configs');
    }
}
