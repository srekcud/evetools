<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move the authorization status from users to characters, every character starting valid';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE characters ADD auth_status VARCHAR(20) DEFAULT 'valid' NOT NULL");
        $this->addSql('ALTER TABLE characters ALTER auth_status DROP DEFAULT');
        $this->addSql('ALTER TABLE users DROP auth_status');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD auth_status VARCHAR(20) DEFAULT 'valid' NOT NULL");
        $this->addSql('ALTER TABLE users ALTER auth_status DROP DEFAULT');
        $this->addSql("UPDATE users SET auth_status = 'invalid' WHERE id IN (SELECT user_id FROM characters WHERE auth_status = 'invalid')");
        $this->addSql('ALTER TABLE characters DROP auth_status');
    }
}
