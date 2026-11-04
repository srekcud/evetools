<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the ship type of each character enrolled in a mining operation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_operation_characters ADD ship_type_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_operation_characters DROP ship_type_id');
    }
}
