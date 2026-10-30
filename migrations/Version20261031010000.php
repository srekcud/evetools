<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track the quantity of a corporation project contribution imported or declined per project and character';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pve_income ADD corp_project_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE pve_income ADD corp_project_character_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE pve_income ADD corp_project_quantity BIGINT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_5586A343A76ED395BE9A0191 ON pve_income (user_id, corp_project_id)');
        $this->addSql("UPDATE pve_income SET corp_project_quantity = CAST(substring(description FROM '.*: ([0-9]+)x ') AS BIGINT) WHERE type = 'corp_project' AND corp_project_id IS NULL AND transaction_id < 0");
        $this->addSql("ALTER TABLE user_pve_settings ADD declined_corp_project_quantities JSON DEFAULT '{}' NOT NULL");
        $this->addSql('ALTER TABLE user_pve_settings ALTER declined_corp_project_quantities DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_pve_settings DROP declined_corp_project_quantities');
        $this->addSql('DROP INDEX IDX_5586A343A76ED395BE9A0191');
        $this->addSql('ALTER TABLE pve_income DROP corp_project_quantity');
        $this->addSql('ALTER TABLE pve_income DROP corp_project_character_id');
        $this->addSql('ALTER TABLE pve_income DROP corp_project_id');
    }
}
