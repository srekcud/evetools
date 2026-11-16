<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the value source and the units awaiting a wallet transaction of a material contribution, and link purchases to the contribution that consumed them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_contributions ADD value_source VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_contributions ADD awaiting_quantity INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE industry_project_purchases ADD contribution_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_purchases ADD CONSTRAINT FK_6D77BE2CFE5E5FBD FOREIGN KEY (contribution_id) REFERENCES industry_project_contributions (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_6D77BE2CFE5E5FBD ON industry_project_purchases (contribution_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_purchases DROP CONSTRAINT FK_6D77BE2CFE5E5FBD');
        $this->addSql('DROP INDEX IDX_6D77BE2CFE5E5FBD');
        $this->addSql('ALTER TABLE industry_project_purchases DROP COLUMN contribution_id');
        $this->addSql('ALTER TABLE industry_project_contributions DROP COLUMN awaiting_quantity');
        $this->addSql('ALTER TABLE industry_project_contributions DROP COLUMN value_source');
    }
}
