<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260128123049 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_projects ADD personal_use BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE users ALTER industry_blacklist_group_ids DROP DEFAULT');
        $this->addSql('ALTER TABLE users ALTER industry_blacklist_type_ids DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_projects DROP personal_use');
        $this->addSql('ALTER TABLE users ALTER industry_blacklist_group_ids SET DEFAULT \'[]\'');
        $this->addSql('ALTER TABLE users ALTER industry_blacklist_type_ids SET DEFAULT \'[]\'');
    }
}
