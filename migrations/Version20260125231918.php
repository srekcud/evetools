<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260125231918 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pve_income table for loot sales and declined_loot_sale_transaction_ids to user_pve_settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pve_income (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            type VARCHAR(50) NOT NULL,
            description VARCHAR(255) NOT NULL,
            amount DOUBLE PRECISION NOT NULL,
            date DATE NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            transaction_id BIGINT DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX IDX_pve_income_user_date ON pve_income (user_id, date)');
        $this->addSql('CREATE INDEX IDX_pve_income_user_transaction ON pve_income (user_id, transaction_id)');
        $this->addSql('COMMENT ON COLUMN pve_income.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN pve_income.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN pve_income.date IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN pve_income.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE pve_income ADD CONSTRAINT FK_pve_income_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('ALTER TABLE user_pve_settings ADD declined_loot_sale_transaction_ids JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pve_income');
        $this->addSql('ALTER TABLE user_pve_settings DROP declined_loot_sale_transaction_ids');
    }
}
