<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link a group industry sale to the wallet transaction it records';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE group_industry_sales ADD wallet_transaction_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE group_industry_sales ADD CONSTRAINT FK_4C462DD8924C1837 FOREIGN KEY (wallet_transaction_id) REFERENCES cached_wallet_transactions (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4C462DD8924C1837 ON group_industry_sales (wallet_transaction_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE group_industry_sales DROP CONSTRAINT FK_4C462DD8924C1837');
        $this->addSql('DROP INDEX UNIQ_4C462DD8924C1837');
        $this->addSql('ALTER TABLE group_industry_sales DROP wallet_transaction_id');
    }
}
