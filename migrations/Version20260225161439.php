<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260225161439 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE industry_bpc_prices (id UUID NOT NULL, blueprint_type_id INT NOT NULL, price_per_run DOUBLE PRECISION NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A35D383EA76ED395 ON industry_bpc_prices (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A35D383EA76ED39522AB3297 ON industry_bpc_prices (user_id, blueprint_type_id)');
        $this->addSql('ALTER TABLE industry_bpc_prices ADD CONSTRAINT FK_A35D383EA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_bpc_prices DROP CONSTRAINT FK_A35D383EA76ED395');
        $this->addSql('DROP TABLE industry_bpc_prices');
    }
}
