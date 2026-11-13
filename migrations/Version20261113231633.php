<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113231633 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Flag the project contributions whose value includes the Jita price of the deposit day';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_contributions ADD valued_at_jita_price BOOLEAN DEFAULT false NOT NULL');
        $this->addSql("UPDATE industry_project_contributions SET valued_at_jita_price = true WHERE type = 'material' AND is_auto_detected = false");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_contributions DROP valued_at_jita_price');
    }
}
