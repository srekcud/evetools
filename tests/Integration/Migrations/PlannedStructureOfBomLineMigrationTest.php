<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Uid\Uuid;

#[Group('issue-337')]
final class PlannedStructureOfBomLineMigrationTest extends IntegrationTestCase
{
    private const string MIGRATION_VERSION = 'Version20261106120000';
    private const string LAST_MIGRATION_BEFORE_THE_PLANNED_STRUCTURE = 'DoctrineMigrations\\Version20261106100000';
    private const string SCHEMA_BEFORE_THE_PLANNED_STRUCTURE = 'planned_structure_before_migration';
    private const string RAITARU_ID = '01970000-0000-7000-8000-0000000000a1';
    private const int HAMMERHEAD_II_RUNS = 50;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->em->getConnection();
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', self::SCHEMA_BEFORE_THE_PLANNED_STRUCTURE));
        $this->connection->executeStatement(sprintf('CREATE SCHEMA %s', self::SCHEMA_BEFORE_THE_PLANNED_STRUCTURE));
        $this->connection->executeStatement(sprintf('SET search_path TO %s', self::SCHEMA_BEFORE_THE_PLANNED_STRUCTURE));
        $this->migrateToTheLastMigrationBeforeThePlannedStructure();
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('SET search_path TO public');
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', self::SCHEMA_BEFORE_THE_PLANNED_STRUCTURE));
        parent::tearDown();
    }

    public function testPlannedStructureColumnsAreAddedNullableAndTheExistingJobLineIsKeptWithoutPlannedStructure(): void
    {
        $bomLineId = $this->seedAFinalJobLine();
        self::assertSame([], $this->plannedStructureColumns());

        $this->migrateUp();

        self::assertSame(
            ['planned_structure_id' => 'YES', 'planned_structure_status' => 'YES'],
            $this->plannedStructureColumns(),
        );
        self::assertSame(
            [self::HAMMERHEAD_II_RUNS, 'final', null, null],
            [
                (int) $this->connection->fetchOne('SELECT runs FROM industry_project_bom_lines WHERE id = :id', ['id' => $bomLineId]),
                $this->connection->fetchOne('SELECT job_group FROM industry_project_bom_lines WHERE id = :id', ['id' => $bomLineId]),
                $this->connection->fetchOne('SELECT planned_structure_id FROM industry_project_bom_lines WHERE id = :id', ['id' => $bomLineId]),
                $this->connection->fetchOne('SELECT planned_structure_status FROM industry_project_bom_lines WHERE id = :id', ['id' => $bomLineId]),
            ],
        );
    }

    public function testJobLineCanKeepItsPlannedStructureAfterTheMigration(): void
    {
        $bomLineId = $this->seedAFinalJobLine();
        $this->migrateUp();

        $this->connection->executeStatement(
            "UPDATE industry_project_bom_lines SET planned_structure_id = :structureId, planned_structure_status = 'best_outside_favorite_system' WHERE id = :id",
            ['structureId' => self::RAITARU_ID, 'id' => $bomLineId],
        );

        self::assertSame(
            [self::RAITARU_ID, 'best_outside_favorite_system'],
            [
                $this->connection->fetchOne('SELECT planned_structure_id FROM industry_project_bom_lines WHERE id = :id', ['id' => $bomLineId]),
                $this->connection->fetchOne('SELECT planned_structure_status FROM industry_project_bom_lines WHERE id = :id', ['id' => $bomLineId]),
            ],
        );
    }

    public function testTheMigrationCannotBeReverted(): void
    {
        $this->expectException(IrreversibleMigration::class);

        $this->loadMigration()->down(new Schema());
    }

    private function seedAFinalJobLine(): string
    {
        $userId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO users (id, created_at, industry_blacklist_group_ids, industry_blacklist_type_ids) VALUES (:id, '2026-10-01 08:00:00', '[]', '[]')",
            ['id' => $userId],
        );
        $projectId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO industry_projects (id, status, short_link_code, created_at, owner_id, blacklist_group_ids, blacklist_type_ids) VALUES (:id, 'in_progress', 'b3c7d4e5f6', '2026-11-01 10:00:00', :ownerId, '[]', '[]')",
            ['id' => $projectId, 'ownerId' => $userId],
        );
        $bomLineId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO industry_project_bom_lines (id, type_id, type_name, required_quantity, fulfilled_quantity, is_job, job_group, activity_type, runs, me_level, te_level, project_id) VALUES (:id, 2185, :typeName, :runs, 0, true, 'final', 'manufacturing', :runs, 2, 4, :projectId)",
            ['id' => $bomLineId, 'typeName' => 'Hammerhead II', 'runs' => self::HAMMERHEAD_II_RUNS, 'projectId' => $projectId],
        );

        return $bomLineId;
    }

    private function plannedStructureColumns(): array
    {
        $columns = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT column_name, is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'industry_project_bom_lines' AND column_name IN ('planned_structure_id', 'planned_structure_status') ORDER BY column_name",
        ) as $column) {
            $columns[$column['column_name']] = $column['is_nullable'];
        }

        return $columns;
    }

    private function migrateToTheLastMigrationBeforeThePlannedStructure(): void
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(
            new ArrayInput([
                'command' => 'doctrine:migrations:migrate',
                'version' => self::LAST_MIGRATION_BEFORE_THE_PLANNED_STRUCTURE,
                '--no-interaction' => true,
            ]),
            $output,
        );

        self::assertSame(0, $exitCode, $output->fetch());
    }

    private function loadMigration(): AbstractMigration
    {
        $migrationFile = self::$kernel->getProjectDir().'/migrations/'.self::MIGRATION_VERSION.'.php';
        self::assertFileExists($migrationFile);
        require_once $migrationFile;
        $migrationClass = 'DoctrineMigrations\\'.self::MIGRATION_VERSION;
        $migration = new $migrationClass($this->connection, new NullLogger());
        \assert($migration instanceof AbstractMigration);

        return $migration;
    }

    private function migrateUp(): void
    {
        $migration = $this->loadMigration();
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
