<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the participant who bought each linked purchase of an industry project, the project owner for the existing ones';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_purchases ADD buyer_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_project_purchases ADD CONSTRAINT FK_6D77BE2C6C755722 FOREIGN KEY (buyer_id) REFERENCES industry_project_participants (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_6D77BE2C6C755722 ON industry_project_purchases (buyer_id)');
        $this->addSql('UPDATE industry_project_purchases purchase SET buyer_id = owner_participant.id FROM industry_project_bom_lines bom_line JOIN industry_projects project ON project.id = bom_line.project_id JOIN industry_project_participants owner_participant ON owner_participant.project_id = project.id AND owner_participant.user_id = project.owner_id WHERE bom_line.id = purchase.bom_line_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_purchases DROP CONSTRAINT FK_6D77BE2C6C755722');
        $this->addSql('DROP INDEX IDX_6D77BE2C6C755722');
        $this->addSql('ALTER TABLE industry_project_purchases DROP COLUMN buyer_id');
    }
}
