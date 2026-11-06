<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make the estimated value of an industry project contribution nullable (unknown price) and allow one derived contribution per member, line and type';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_contributions ALTER estimated_value DROP NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_industry_project_derived_contribution ON industry_project_contributions (project_id, member_id, bom_item_id, type) WHERE (is_auto_detected = true)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('A contribution without a known value cannot be given a value back.');
    }
}
