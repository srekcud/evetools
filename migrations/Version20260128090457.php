<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260128090457 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_projects ALTER excluded_type_ids DROP DEFAULT');
        $this->addSql('ALTER TABLE users ADD industry_blacklist_group_ids JSON NOT NULL DEFAULT \'[]\'');
        $this->addSql('ALTER TABLE users ADD industry_blacklist_type_ids JSON NOT NULL DEFAULT \'[]\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_projects ALTER excluded_type_ids SET DEFAULT \'[]\'');
        $this->addSql('ALTER TABLE users DROP industry_blacklist_group_ids');
        $this->addSql('ALTER TABLE users DROP industry_blacklist_type_ids');
    }
}
