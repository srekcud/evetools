<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Replays every migration on a brand new, empty database (issue #75), then checks
 * that the resulting schema matches the entity mapping, as `doctrine:schema:validate` does.
 *
 * The throwaway database lives next to the test database and is opened with plain DBAL
 * connections: the dama/doctrine-test-bundle connection is wrapped in a transaction,
 * where neither CREATE DATABASE nor a migration run is possible.
 */
#[Group('slow')]
final class MigrationsReplayTest extends KernelTestCase
{
    private const string MIGRATIONS_NAMESPACE = 'DoctrineMigrations';
    private const string MIGRATIONS_TABLE = 'doctrine_migration_versions';
    private const string MAINTENANCE_DATABASE = 'postgres';

    private Connection $maintenanceConnection;
    private ?Connection $replayConnection = null;
    private string $replayDatabaseName;

    /** @var array<string, mixed> */
    private array $replayConnectionParams;

    protected function setUp(): void
    {
        self::bootKernel();
        $testConnectionParams = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->getParams();

        $serverParams = array_intersect_key(
            $testConnectionParams,
            array_flip(['driver', 'host', 'port', 'user', 'password', 'serverVersion', 'charset']),
        );
        $this->replayDatabaseName = $testConnectionParams['dbname'].'_migrations_replay';
        $this->replayConnectionParams = [...$serverParams, 'dbname' => $this->replayDatabaseName];

        $this->maintenanceConnection = DriverManager::getConnection([...$serverParams, 'dbname' => self::MAINTENANCE_DATABASE]);
        $this->dropReplayDatabase();
        $this->maintenanceConnection->executeStatement(\sprintf('CREATE DATABASE "%s"', $this->replayDatabaseName));
    }

    protected function tearDown(): void
    {
        $this->replayConnection?->close();
        $this->dropReplayDatabase();
        $this->maintenanceConnection->close();

        parent::tearDown();
    }

    public function testAllMigrationsReplayOnAnEmptyDatabaseAndMatchTheMapping(): void
    {
        $this->replayConnection = DriverManager::getConnection($this->replayConnectionParams, $this->configurationIgnoringMigrationsTable());

        $migrationsDependencyFactory = DependencyFactory::fromConnection(
            new ConfigurationArray([
                'migrations_paths' => [self::MIGRATIONS_NAMESPACE => self::$kernel->getProjectDir().'/migrations'],
                'table_storage' => ['table_name' => self::MIGRATIONS_TABLE],
            ]),
            new ExistingConnection($this->replayConnection),
        );
        $migrationsDependencyFactory->getMetadataStorage()->ensureInitialized();
        $latestVersion = $migrationsDependencyFactory->getVersionAliasResolver()->resolveVersionAlias('latest');
        $migrationPlan = $migrationsDependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion($latestVersion);
        $availableMigrationsCount = \count($migrationsDependencyFactory->getMigrationRepository()->getMigrations());

        // Throws on the first failing migration, with its SQL error.
        $migrationsDependencyFactory->getMigrator()->migrate($migrationPlan, new MigratorConfiguration());

        self::assertSame(
            $availableMigrationsCount,
            \count($migrationsDependencyFactory->getMetadataStorage()->getExecutedMigrations()->getItems()),
            'Every available migration must be recorded as executed.',
        );

        $testEntityManager = self::getContainer()->get(EntityManagerInterface::class);
        $replayEntityManager = new EntityManager($this->replayConnection, $testEntityManager->getConfiguration());

        self::assertSame(
            [],
            (new SchemaValidator($replayEntityManager))->getUpdateSchemaList(),
            'The schema built by the migrations must match the entity mapping (doctrine:schema:validate).',
        );
    }

    /** Mirrors `doctrine:schema:validate`, which leaves the migrations bookkeeping table out of the comparison. */
    private function configurationIgnoringMigrationsTable(): DbalConfiguration
    {
        $configuration = new DbalConfiguration();
        $configuration->setSchemaAssetsFilter(
            static fn (string|AbstractAsset $asset): bool => ($asset instanceof AbstractAsset ? $asset->getName() : $asset) !== self::MIGRATIONS_TABLE,
        );

        return $configuration;
    }

    private function dropReplayDatabase(): void
    {
        $this->maintenanceConnection->executeStatement(\sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', $this->replayDatabaseName));
    }
}
