<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the contract fee lines of the character wallet journal and the contract of a project sale';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cached_wallet_journal_entries (id UUID NOT NULL, journal_entry_id BIGINT NOT NULL, ref_type VARCHAR(64) NOT NULL, amount DOUBLE PRECISION NOT NULL, date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, context_id BIGINT DEFAULT NULL, context_id_type VARCHAR(32) DEFAULT NULL, character_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_CD416401136BE75 ON cached_wallet_journal_entries (character_id)');
        $this->addSql('CREATE INDEX IDX_CD416406B00C1CF ON cached_wallet_journal_entries (context_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CD416401136BE756A86E4FB ON cached_wallet_journal_entries (character_id, journal_entry_id)');
        $this->addSql('ALTER TABLE cached_wallet_journal_entries ADD CONSTRAINT FK_CD416401136BE75 FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE industry_project_sales ADD contract_id BIGINT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C95DAA692576E0FD ON industry_project_sales (contract_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_C95DAA692576E0FD');
        $this->addSql('ALTER TABLE industry_project_sales DROP contract_id');
        $this->addSql('DROP TABLE cached_wallet_journal_entries');
    }
}
