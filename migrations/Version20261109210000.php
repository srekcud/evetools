<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the table of the line reservations of an industry project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE industry_project_line_reservations (id UUID NOT NULL, quantity INT NOT NULL, reserved_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, bom_line_id UUID NOT NULL, participant_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_DD68D8FFB2EEEC4D ON industry_project_line_reservations (bom_line_id)');
        $this->addSql('CREATE INDEX IDX_DD68D8FF9D1C3019 ON industry_project_line_reservations (participant_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DD68D8FFB2EEEC4D9D1C3019 ON industry_project_line_reservations (bom_line_id, participant_id)');
        $this->addSql('ALTER TABLE industry_project_line_reservations ADD CONSTRAINT FK_DD68D8FFB2EEEC4D FOREIGN KEY (bom_line_id) REFERENCES industry_project_bom_lines (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE industry_project_line_reservations ADD CONSTRAINT FK_DD68D8FF9D1C3019 FOREIGN KEY (participant_id) REFERENCES industry_project_participants (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_line_reservations DROP CONSTRAINT FK_DD68D8FFB2EEEC4D');
        $this->addSql('ALTER TABLE industry_project_line_reservations DROP CONSTRAINT FK_DD68D8FF9D1C3019');
        $this->addSql('DROP TABLE industry_project_line_reservations');
    }
}
