<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the skill points of each cached character skill';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_character_skills ADD skill_points INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_character_skills DROP skill_points');
    }
}
