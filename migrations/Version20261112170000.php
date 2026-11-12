<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the items signature of public contract listings as a sha256 hash';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE public_contract_listings SET items_signature = encode(sha256(convert_to(items_signature, 'UTF8')), 'hex')");
        $this->addSql('ALTER TABLE public_contract_listings ALTER items_signature TYPE VARCHAR(64)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public_contract_listings ALTER items_signature TYPE VARCHAR(1000)');
    }
}
