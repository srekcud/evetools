<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the courier hub and the jump bridge rate to the industry user settings and set the Jita to hub rate to 800';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_user_settings ADD courier_hub_solar_system_id INT DEFAULT 30000772 NOT NULL');
        $this->addSql('ALTER TABLE industry_user_settings ADD jump_bridge_cost_per_m3 DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE industry_user_settings ALTER export_cost_per_m3 SET DEFAULT 800');
        $this->addSql('UPDATE industry_user_settings SET export_cost_per_m3 = 800');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_user_settings ALTER export_cost_per_m3 SET DEFAULT 1200');
        $this->addSql('ALTER TABLE industry_user_settings DROP courier_hub_solar_system_id');
        $this->addSql('ALTER TABLE industry_user_settings DROP jump_bridge_cost_per_m3');
    }
}
