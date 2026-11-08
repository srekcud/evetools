<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the history of public item exchange contracts used to estimate contract sales';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE public_contract_listings (id UUID NOT NULL, contract_id BIGINT NOT NULL, region_id INT NOT NULL, issuer_id BIGINT NOT NULL, price DOUBLE PRECISION NOT NULL, items JSON NOT NULL, items_signature VARCHAR(1000) NOT NULL, type_id INT DEFAULT NULL, quantity INT DEFAULT NULL, date_expired TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, missed_polls INT DEFAULT 0 NOT NULL, disappeared_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_1A61CEB198260155C54C8C93 ON public_contract_listings (region_id, type_id)');
        $this->addSql('CREATE INDEX IDX_1A61CEB198260155F3D58F47 ON public_contract_listings (region_id, missed_polls)');
        $this->addSql('CREATE INDEX IDX_1A61CEB1B81C492A ON public_contract_listings (last_seen_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1A61CEB12576E0FD ON public_contract_listings (contract_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE public_contract_listings');
    }
}
