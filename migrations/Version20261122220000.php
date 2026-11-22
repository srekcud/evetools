<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the decryptor chosen for the invention of each product of the industry projects';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_products ADD invention_decryptor_type_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE industry_project_products DROP invention_decryptor_type_id');
    }
}
