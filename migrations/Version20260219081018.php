<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260219081018 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_user_settings ADD broker_fee_rate DOUBLE PRECISION DEFAULT 0.036 NOT NULL');
        $this->addSql('ALTER TABLE industry_user_settings ADD sales_tax_rate DOUBLE PRECISION DEFAULT 0.036 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_user_settings DROP broker_fee_rate');
        $this->addSql('ALTER TABLE industry_user_settings DROP sales_tax_rate');
    }
}
