<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add when and by whom the surplus of an industry project was declared stored in the corporation stock';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_projects ADD surplus_stored_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_projects ADD surplus_stored_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_projects ADD CONSTRAINT FK_7CCBA9217C7B330 FOREIGN KEY (surplus_stored_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_7CCBA9217C7B330 ON industry_projects (surplus_stored_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_projects DROP CONSTRAINT FK_7CCBA9217C7B330');
        $this->addSql('DROP INDEX IDX_7CCBA9217C7B330');
        $this->addSql('ALTER TABLE industry_projects DROP surplus_stored_at');
        $this->addSql('ALTER TABLE industry_projects DROP surplus_stored_by_id');
    }
}
