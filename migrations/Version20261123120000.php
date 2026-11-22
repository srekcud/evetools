<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the blueprints attached to the job steps of industry projects';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE industry_project_line_blueprints (id UUID NOT NULL, project_id UUID NOT NULL, bom_line_id UUID NOT NULL, attached_by_id UUID DEFAULT NULL, item_id BIGINT NOT NULL, attached_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_BB4EBFBC166D1F9C ON industry_project_line_blueprints (project_id)');
        $this->addSql('CREATE INDEX IDX_BB4EBFBCB2EEEC4D ON industry_project_line_blueprints (bom_line_id)');
        $this->addSql('CREATE INDEX IDX_BB4EBFBCA7B6C524 ON industry_project_line_blueprints (attached_by_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BB4EBFBC166D1F9C126F525E ON industry_project_line_blueprints (project_id, item_id)');
        $this->addSql('ALTER TABLE industry_project_line_blueprints ADD CONSTRAINT FK_LINE_BLUEPRINTS_PROJECT FOREIGN KEY (project_id) REFERENCES industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE industry_project_line_blueprints ADD CONSTRAINT FK_LINE_BLUEPRINTS_BOM_LINE FOREIGN KEY (bom_line_id) REFERENCES industry_project_bom_lines (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE industry_project_line_blueprints ADD CONSTRAINT FK_LINE_BLUEPRINTS_ATTACHED_BY FOREIGN KEY (attached_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE industry_project_line_blueprints');
    }
}
