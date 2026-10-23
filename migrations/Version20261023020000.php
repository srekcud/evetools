<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Leave the cost and the product of a cached industry job unknown when ESI does not send them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cached_industry_jobs ALTER cost DROP NOT NULL');
        $this->addSql('ALTER TABLE cached_industry_jobs ALTER product_type_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE cached_industry_jobs SET cost = 0 WHERE cost IS NULL');
        $this->addSql('UPDATE cached_industry_jobs SET product_type_id = blueprint_type_id WHERE product_type_id IS NULL');
        $this->addSql('ALTER TABLE cached_industry_jobs ALTER cost SET NOT NULL');
        $this->addSql('ALTER TABLE cached_industry_jobs ALTER product_type_id SET NOT NULL');
    }
}
