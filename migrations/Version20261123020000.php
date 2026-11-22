<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the station transfers of industry projects: one leg between two places, grouped by route';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE industry_project_station_transfers (id UUID NOT NULL, project_id UUID NOT NULL, declared_by_id UUID DEFAULT NULL, route_id UUID NOT NULL, leg_index INT NOT NULL, carrier_kind VARCHAR(20) NOT NULL, carrier_character_id BIGINT DEFAULT NULL, contract_id BIGINT DEFAULT NULL, from_solar_system_id INT NOT NULL, from_location_id BIGINT DEFAULT NULL, to_solar_system_id INT NOT NULL, to_location_id BIGINT DEFAULT NULL, lines JSON NOT NULL, declared_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, arrived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_E6BFF887166D1F9C ON industry_project_station_transfers (project_id)');
        $this->addSql('CREATE INDEX IDX_E6BFF887C48B85B0 ON industry_project_station_transfers (declared_by_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E6BFF88734ECB4E631CD9094 ON industry_project_station_transfers (route_id, leg_index)');
        $this->addSql('ALTER TABLE industry_project_station_transfers ADD CONSTRAINT FK_PROJECT_STATION_TRANSFERS_PROJECT FOREIGN KEY (project_id) REFERENCES industry_projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE industry_project_station_transfers ADD CONSTRAINT FK_PROJECT_STATION_TRANSFERS_DECLARED_BY FOREIGN KEY (declared_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE industry_project_station_transfers');
    }
}
