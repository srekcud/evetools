<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create industry_stockpile_imports table recording the product, runs and ME of each stockpile import';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE industry_stockpile_imports (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, product_type_id INT NOT NULL, runs INT NOT NULL, me INT NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_978AC2F4A76ED395 ON industry_stockpile_imports (user_id)');
        $this->addSql('ALTER TABLE industry_stockpile_imports ADD CONSTRAINT FK_978AC2F4A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_stockpile_imports DROP CONSTRAINT FK_978AC2F4A76ED395');
        $this->addSql('DROP TABLE industry_stockpile_imports');
    }
}
