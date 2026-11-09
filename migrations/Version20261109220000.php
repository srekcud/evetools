<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the table of the transfers ticked as received between the members of an industry project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE industry_project_transfer_receipts (id UUID NOT NULL, amount_cents BIGINT NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, from_participant_id UUID NOT NULL, to_participant_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_184B614C166D1F9C ON industry_project_transfer_receipts (project_id)');
        $this->addSql('CREATE INDEX IDX_184B614CF33E62FF ON industry_project_transfer_receipts (from_participant_id)');
        $this->addSql('CREATE INDEX IDX_184B614CFF4E9CCD ON industry_project_transfer_receipts (to_participant_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_184B614C166D1F9CF33E62FFFF4E9CCD ON industry_project_transfer_receipts (project_id, from_participant_id, to_participant_id)');
        $this->addSql('ALTER TABLE industry_project_transfer_receipts ADD CONSTRAINT FK_184B614C166D1F9C FOREIGN KEY (project_id) REFERENCES industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE industry_project_transfer_receipts ADD CONSTRAINT FK_184B614CF33E62FF FOREIGN KEY (from_participant_id) REFERENCES industry_project_participants (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE industry_project_transfer_receipts ADD CONSTRAINT FK_184B614CFF4E9CCD FOREIGN KEY (to_participant_id) REFERENCES industry_project_participants (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE industry_project_transfer_receipts');
    }
}
