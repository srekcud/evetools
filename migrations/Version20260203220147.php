<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260203220147 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE industry_rig_definitions_id_seq CASCADE');
        $this->addSql('CREATE TABLE sde_inv_type_materials (type_id INT NOT NULL, material_type_id INT NOT NULL, quantity INT NOT NULL, PRIMARY KEY (type_id, material_type_id))');
        $this->addSql('CREATE INDEX IDX_9779468CC54C8C93 ON sde_inv_type_materials (type_id)');
        $this->addSql('CREATE INDEX IDX_9779468C74D6573C ON sde_inv_type_materials (material_type_id)');
        $this->addSql('DROP TABLE industry_rig_definitions');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE industry_rig_definitions_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE industry_rig_definitions (id INT DEFAULT nextval(\'industry_rig_definitions_id_seq\'::regclass) NOT NULL, rig_name VARCHAR(255) NOT NULL, rig_type_id INT NOT NULL, material_bonus DOUBLE PRECISION DEFAULT \'0\' NOT NULL, time_bonus DOUBLE PRECISION DEFAULT \'0\' NOT NULL, target_categories JSON DEFAULT \'[]\' NOT NULL, is_reaction BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_rig_definitions_type_id ON industry_rig_definitions (rig_type_id)');
        $this->addSql('CREATE UNIQUE INDEX industry_rig_definitions_rig_type_id_key ON industry_rig_definitions (rig_type_id)');
        $this->addSql('DROP TABLE sde_inv_type_materials');
    }
}
