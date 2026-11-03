<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103225030 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the boost ship and the mounted compressor kinds of a mining operation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_operations ADD boost_ship_type_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mining_operations ADD mounted_compressor_kinds JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mining_operations DROP boost_ship_type_id');
        $this->addSql('ALTER TABLE mining_operations DROP mounted_compressor_kinds');
    }
}
