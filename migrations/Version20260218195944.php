<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260218195944 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add estimated_job_cost and estimated_sell_price to industry_projects';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_projects ADD estimated_job_cost DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_projects ADD estimated_sell_price DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_projects DROP estimated_job_cost');
        $this->addSql('ALTER TABLE industry_projects DROP estimated_sell_price');
    }
}
