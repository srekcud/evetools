<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Leave the name of a mining entry type missing from the SDE unknown instead of "Type #<id>"';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_entries ALTER type_name DROP NOT NULL');
        $this->addSql("UPDATE mining_entries SET type_name = NULL WHERE type_name LIKE 'Type #%'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE mining_entries SET type_name = 'Type #' || type_id WHERE type_name IS NULL");
        $this->addSql('ALTER TABLE mining_entries ALTER type_name SET NOT NULL');
    }
}
