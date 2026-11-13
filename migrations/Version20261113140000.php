<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the marks of industry project BOM lines declared launched or done outside the application';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE industry_project_bom_line_marks (id UUID NOT NULL, state VARCHAR(20) NOT NULL, runs INT NOT NULL, marked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, line_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_34801A7E4D7B7542 ON industry_project_bom_line_marks (line_id)');
        $this->addSql('ALTER TABLE industry_project_bom_line_marks ADD CONSTRAINT FK_BOM_LINE_MARK_LINE FOREIGN KEY (line_id) REFERENCES industry_project_bom_lines (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE industry_project_bom_line_marks');
    }
}
