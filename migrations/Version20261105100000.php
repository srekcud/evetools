<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105100000 extends AbstractMigration
{
    private const float PREVIOUS_RATE = 0.036;
    private const float PREVIOUS_PERCENT = 3.6;

    public function getDescription(): string
    {
        return 'Drop the entered broker fee and sales tax rates of the Industry settings and make the fees of a group project nullable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_user_settings DROP broker_fee_rate');
        $this->addSql('ALTER TABLE industry_user_settings DROP sales_tax_rate');
        $this->addSql('ALTER TABLE group_industry_projects ALTER broker_fee_percent DROP NOT NULL');
        $this->addSql('ALTER TABLE group_industry_projects ALTER sales_tax_percent DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(sprintf('UPDATE group_industry_projects SET broker_fee_percent = %s WHERE broker_fee_percent IS NULL', self::PREVIOUS_PERCENT));
        $this->addSql(sprintf('UPDATE group_industry_projects SET sales_tax_percent = %s WHERE sales_tax_percent IS NULL', self::PREVIOUS_PERCENT));
        $this->addSql('ALTER TABLE group_industry_projects ALTER broker_fee_percent SET NOT NULL');
        $this->addSql('ALTER TABLE group_industry_projects ALTER sales_tax_percent SET NOT NULL');
        $this->addSql(sprintf('ALTER TABLE industry_user_settings ADD broker_fee_rate DOUBLE PRECISION DEFAULT %s NOT NULL', self::PREVIOUS_RATE));
        $this->addSql(sprintf('ALTER TABLE industry_user_settings ADD sales_tax_rate DOUBLE PRECISION DEFAULT %s NOT NULL', self::PREVIOUS_RATE));
    }
}
