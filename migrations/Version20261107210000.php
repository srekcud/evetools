<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the table linking an industry project to its courier contract';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE industry_project_courier_contracts (id UUID NOT NULL, contract_id BIGINT NOT NULL, character_id BIGINT NOT NULL, linked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BC6FEB45166D1F9C ON industry_project_courier_contracts (project_id)');
        $this->addSql('ALTER TABLE industry_project_courier_contracts ADD CONSTRAINT FK_BC6FEB45166D1F9C FOREIGN KEY (project_id) REFERENCES industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_courier_contracts DROP CONSTRAINT FK_BC6FEB45166D1F9C');
        $this->addSql('DROP TABLE industry_project_courier_contracts');
    }
}
