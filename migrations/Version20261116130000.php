<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the owner characters that produce for an industry project, all of them when not set';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_projects ADD producer_character_ids JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_projects DROP COLUMN producer_character_ids');
    }
}
