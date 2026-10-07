<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #77: imported structures stored before the fix have a locationId but no solar system.
 * Backfill it from the ESI structure cache so the favorite system can pick them.
 */
final class Version20261007150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill the solar system of imported industry structures from the structure cache';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE industry_structure_configs AS isc SET solar_system_id = cs.solar_system_id FROM cached_structure AS cs WHERE isc.location_id = cs.structure_id AND isc.solar_system_id IS NULL AND cs.solar_system_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Data backfill only: the previous NULL values carried no information, nothing to restore.
    }
}
