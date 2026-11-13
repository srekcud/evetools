<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Uid\Uuid;

#[Group('issue-894')]
final class ProjectContainersMigrationTest extends IntegrationTestCase
{
    private const string MIGRATION_VERSION = 'Version20261113170000';
    private const string LAST_MIGRATION_BEFORE_THE_CONTAINERS = 'DoctrineMigrations\\Version20261113160000';
    private const string SCHEMA_BEFORE_THE_CONTAINERS = 'project_containers_before_migration';

    private Connection $connection;

    private string $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->em->getConnection();
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', self::SCHEMA_BEFORE_THE_CONTAINERS));
        $this->connection->executeStatement(sprintf('CREATE SCHEMA %s', self::SCHEMA_BEFORE_THE_CONTAINERS));
        $this->connection->executeStatement(sprintf('SET search_path TO %s', self::SCHEMA_BEFORE_THE_CONTAINERS));
        $this->migrateToTheLastMigrationBeforeTheContainers();
        $this->ownerId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO users (id, created_at, industry_blacklist_group_ids, industry_blacklist_type_ids) VALUES (:id, '2026-10-01 08:00:00', '[]', '[]')",
            ['id' => $this->ownerId],
        );
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('SET search_path TO public');
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', self::SCHEMA_BEFORE_THE_CONTAINERS));
        parent::tearDown();
    }

    public function testEachProjectWithAContainerNameGetsOneTrimmedRowAndTheOthersGetNone(): void
    {
        $named = $this->seedProject('b3c7d4e5f1', '  GI Orca hangar ');
        $blank = $this->seedProject('b3c7d4e5f2', '   ');
        $unnamed = $this->seedProject('b3c7d4e5f3', null);

        $this->migrateUp();

        self::assertSame(
            [[$named, 'GI Orca hangar']],
            array_map(
                static fn (array $row): array => [$row['project_id'], $row['name']],
                $this->connection->fetchAllAssociative('SELECT project_id, name FROM industry_project_containers ORDER BY name'),
            ),
        );
        self::assertNotContains($blank, $this->connection->fetchFirstColumn('SELECT project_id FROM industry_project_containers'));
        self::assertNotContains($unnamed, $this->connection->fetchFirstColumn('SELECT project_id FROM industry_project_containers'));
    }

    public function testTheContainerNameColumnIsDropped(): void
    {
        $this->seedProject('b3c7d4e5f1', 'GI Orca hangar');

        $this->migrateUp();

        self::assertSame(
            0,
            (int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'industry_projects' AND column_name = 'container_name'"),
        );
    }

    public function testAProjectCannotDeclareTheSameNameTwice(): void
    {
        $projectId = $this->seedProject('b3c7d4e5f1', 'GI Orca hangar');
        $this->migrateUp();
        $this->connection->executeStatement('SAVEPOINT duplicate_container');

        $rejected = false;
        try {
            $this->connection->executeStatement(
                'INSERT INTO industry_project_containers (id, project_id, name) VALUES (:id, :projectId, :name)',
                ['id' => Uuid::v7()->toRfc4122(), 'projectId' => $projectId, 'name' => 'GI Orca hangar'],
            );
        } catch (UniqueConstraintViolationException) {
            $rejected = true;
            $this->connection->executeStatement('ROLLBACK TO SAVEPOINT duplicate_container');
        }

        self::assertTrue($rejected);
    }

    public function testDeletingAProjectDeletesItsContainers(): void
    {
        $projectId = $this->seedProject('b3c7d4e5f1', 'GI Orca hangar');
        $this->migrateUp();

        $this->connection->executeStatement('DELETE FROM industry_projects WHERE id = :id', ['id' => $projectId]);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM industry_project_containers'));
    }

    public function testRevertingRestoresTheFirstNameInAlphabeticalOrderOfEachProjectAndDropsTheTable(): void
    {
        $projectId = $this->seedProject('b3c7d4e5f1', 'First');
        $this->migrateUp();
        $this->connection->executeStatement(
            'INSERT INTO industry_project_containers (id, project_id, name) VALUES (:id, :projectId, :name)',
            ['id' => Uuid::v7()->toRfc4122(), 'projectId' => $projectId, 'name' => 'Second'],
        );

        $this->migrateDown();

        self::assertSame(
            ['First', 0],
            [
                $this->connection->fetchOne('SELECT container_name FROM industry_projects WHERE id = :id', ['id' => $projectId]),
                (int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'industry_project_containers'"),
            ],
        );
    }

    private function seedProject(string $shortLinkCode, ?string $containerName): string
    {
        $projectId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO industry_projects (id, status, short_link_code, created_at, owner_id, blacklist_group_ids, blacklist_type_ids, container_name) VALUES (:id, 'in_progress', :shortLinkCode, '2026-11-01 10:00:00', :ownerId, '[]', '[]', :containerName)",
            ['id' => $projectId, 'shortLinkCode' => $shortLinkCode, 'ownerId' => $this->ownerId, 'containerName' => $containerName],
        );

        return $projectId;
    }

    private function migrateToTheLastMigrationBeforeTheContainers(): void
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(
            new ArrayInput([
                'command' => 'doctrine:migrations:migrate',
                'version' => self::LAST_MIGRATION_BEFORE_THE_CONTAINERS,
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
        $this->executeStatementsOf($migration);
    }

    private function migrateDown(): void
    {
        $migration = $this->loadMigration();
        $migration->down(new Schema());
        $this->executeStatementsOf($migration);
    }

    private function executeStatementsOf(AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
