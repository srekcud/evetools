<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Functional index serving the case-insensitive type name lookups (LOWER(type_name) IN (...)).
 *
 * It cannot be declared in the entity mapping: DBAL does not model expression indexes. Its
 * schema introspection ignores them too, so doctrine:migrations:diff never proposes to drop it.
 * The test database is built from the mapping: `make test-db` creates it separately.
 */
final class Version20261008090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a functional index on LOWER(type_name) to sde_inv_types';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_sde_inv_types_lower_type_name ON sde_inv_types (LOWER(type_name))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_sde_inv_types_lower_type_name');
    }
}
