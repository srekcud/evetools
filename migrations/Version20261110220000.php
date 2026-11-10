<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the reason and the sender of the incoming donations of the character wallet journal';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_wallet_journal_entries ADD reason VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE cached_wallet_journal_entries ADD first_party_id BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_wallet_journal_entries DROP reason');
        $this->addSql('ALTER TABLE cached_wallet_journal_entries DROP first_party_id');
    }
}
