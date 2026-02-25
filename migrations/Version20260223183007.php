<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223183007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create industry_stockpile_targets table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE industry_stockpile_targets (id UUID NOT NULL, type_id INT NOT NULL, type_name VARCHAR(255) NOT NULL, target_quantity INT NOT NULL, stage VARCHAR(20) NOT NULL, source_product_type_id INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_405C31FBA76ED395 ON industry_stockpile_targets (user_id)');
        $this->addSql('CREATE INDEX IDX_405C31FBC27C9369 ON industry_stockpile_targets (stage)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_type ON industry_stockpile_targets (user_id, type_id)');
        $this->addSql('ALTER TABLE industry_stockpile_targets ADD CONSTRAINT FK_405C31FBA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_stockpile_targets DROP CONSTRAINT FK_405C31FBA76ED395');
        $this->addSql('DROP TABLE industry_stockpile_targets');
    }
}
