<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the seller character of a group industry sale';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE group_industry_sales ADD seller_character_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE group_industry_sales ADD CONSTRAINT FK_4C462DD8449E3089 FOREIGN KEY (seller_character_id) REFERENCES characters (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_4C462DD8449E3089 ON group_industry_sales (seller_character_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE group_industry_sales DROP CONSTRAINT FK_4C462DD8449E3089');
        $this->addSql('DROP INDEX IDX_4C462DD8449E3089');
        $this->addSql('ALTER TABLE group_industry_sales DROP seller_character_id');
    }
}
