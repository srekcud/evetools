<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the planetary products excluded from the stock coverage to users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD planetary_excluded_type_ids JSON DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP planetary_excluded_type_ids');
    }
}
