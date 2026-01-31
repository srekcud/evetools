<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260131130430 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add locationId and corporationId to industry_structure_configs for corporation sharing';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industry_structure_configs ADD location_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE industry_structure_configs ADD corporation_id BIGINT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_4FE4C207B268536964D218E ON industry_structure_configs (corporation_id, location_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IDX_4FE4C207B268536964D218E');
        $this->addSql('ALTER TABLE industry_structure_configs DROP location_id');
        $this->addSql('ALTER TABLE industry_structure_configs DROP corporation_id');
    }
}
