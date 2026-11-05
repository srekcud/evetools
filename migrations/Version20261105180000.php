<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the job matches and the purchases linked to the industry project BOM lines';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE industry_project_job_matches (
              id UUID NOT NULL,
              esi_job_id INT NOT NULL,
              cost DOUBLE PRECISION DEFAULT NULL,
              status VARCHAR(30) NOT NULL,
              end_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              runs INT NOT NULL,
              character_name VARCHAR(255) DEFAULT NULL,
              facility_id BIGINT DEFAULT NULL,
              facility_name VARCHAR(255) DEFAULT NULL,
              matched_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              manual BOOLEAN NOT NULL,
              bom_line_id UUID NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_52C05C1EB2EEEC4D ON industry_project_job_matches (bom_line_id)');
        $this->addSql('CREATE INDEX IDX_52C05C1EBD719596 ON industry_project_job_matches (esi_job_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE industry_project_purchases (
              id UUID NOT NULL,
              type_id INT NOT NULL,
              quantity INT NOT NULL,
              unit_price DOUBLE PRECISION NOT NULL,
              total_price DOUBLE PRECISION NOT NULL,
              source VARCHAR(20) NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              bom_line_id UUID NOT NULL,
              transaction_id UUID DEFAULT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_6D77BE2C2FC0CB0F ON industry_project_purchases (transaction_id)');
        $this->addSql('CREATE INDEX IDX_6D77BE2CB2EEEC4D ON industry_project_purchases (bom_line_id)');
        $this->addSql('CREATE INDEX IDX_6D77BE2CC54C8C93 ON industry_project_purchases (type_id)');
        $this->addSql('ALTER TABLE industry_project_job_matches ADD CONSTRAINT FK_52C05C1EB2EEEC4D FOREIGN KEY (bom_line_id) REFERENCES industry_project_bom_lines (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE industry_project_purchases ADD CONSTRAINT FK_6D77BE2CB2EEEC4D FOREIGN KEY (bom_line_id) REFERENCES industry_project_bom_lines (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE industry_project_purchases ADD CONSTRAINT FK_6D77BE2C2FC0CB0F FOREIGN KEY (transaction_id) REFERENCES cached_wallet_transactions (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The job matches and the purchases linked to the BOM lines cannot be restored.');
    }
}
