<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the sale date of an escalation and give each sold escalation a PVE income';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE escalations ADD sold_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE pve_income ADD escalation_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE pve_income ADD CONSTRAINT FK_5586A343703EE70D FOREIGN KEY (escalation_id) REFERENCES escalations (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5586A343703EE70D ON pve_income (escalation_id)');
        $this->addSql("UPDATE escalations SET sold_at = updated_at WHERE sale_status = 'vendu' AND sold_at IS NULL");
        $this->addSql("INSERT INTO pve_income (id, user_id, type, description, amount, date, created_at, escalation_id)
            SELECT gen_random_uuid(), e.user_id, 'escalation', e.type || ' - ' || e.solar_system_name, e.price, e.sold_at, NOW(), e.id
            FROM escalations e
            WHERE e.sale_status = 'vendu'
              AND NOT EXISTS (SELECT 1 FROM pve_income i WHERE i.escalation_id = e.id)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM pve_income WHERE type = 'escalation'");
        $this->addSql('ALTER TABLE pve_income DROP CONSTRAINT FK_5586A343703EE70D');
        $this->addSql('DROP INDEX UNIQ_5586A343703EE70D');
        $this->addSql('ALTER TABLE pve_income DROP escalation_id');
        $this->addSql('ALTER TABLE escalations DROP sold_at');
    }
}
