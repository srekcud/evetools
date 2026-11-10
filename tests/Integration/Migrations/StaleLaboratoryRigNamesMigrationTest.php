<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Uid\Uuid;

#[Group('issue-523')]
final class StaleLaboratoryRigNamesMigrationTest extends IntegrationTestCase
{
    private const string MIGRATION_VERSION = 'Version20261110040000';
    private const string LAST_MIGRATION_BEFORE_THE_RENAME = 'DoctrineMigrations\\Version20261110030000';
    private const string SCHEMA_BEFORE_THE_RENAME = 'stale_rig_names_before_migration';

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->em->getConnection();
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', self::SCHEMA_BEFORE_THE_RENAME));
        $this->connection->executeStatement(sprintf('CREATE SCHEMA %s', self::SCHEMA_BEFORE_THE_RENAME));
        $this->connection->executeStatement(sprintf('SET search_path TO %s', self::SCHEMA_BEFORE_THE_RENAME));
        $this->migrateToTheLastMigrationBeforeTheRename();
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('SET search_path TO public');
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', self::SCHEMA_BEFORE_THE_RENAME));
        parent::tearDown();
    }

    public function testRemovedLaboratoryOptimizationRigsAreRenamedToTheCopyRigOfTheSameSizeAndTimeBonusWhileOtherRigsAndOrderAreKept(): void
    {
        $raitaru = $this->seedStructure(['Standup M-Set Laboratory Optimization II', 'Standup M-Set Equipment Manufacturing Material Efficiency II']);
        $azbel = $this->seedStructure(['Standup L-Set Laboratory Optimization I', 'Standup L-Set Laboratory Optimization II']);
        $raitaruTier1 = $this->seedStructure(['Standup M-Set Laboratory Optimization I']);
        $sotiyo = $this->seedStructure(['Standup XL-Set Laboratory Optimization II']);
        $withoutRigs = $this->seedStructure([]);

        $this->migrateUp();

        self::assertSame(
            [
                ['Standup M-Set Blueprint Copy Accelerator II', 'Standup M-Set Equipment Manufacturing Material Efficiency II'],
                ['Standup L-Set Blueprint Copy Optimization I', 'Standup L-Set Blueprint Copy Optimization II'],
                ['Standup M-Set Blueprint Copy Accelerator I'],
                ['Standup XL-Set Laboratory Optimization II'],
                [],
            ],
            [
                $this->rigsOf($raitaru),
                $this->rigsOf($azbel),
                $this->rigsOf($raitaruTier1),
                $this->rigsOf($sotiyo),
                $this->rigsOf($withoutRigs),
            ],
        );
    }

    public function testRenamedRigAlreadyFittedUnderItsNewNameIsNotDuplicated(): void
    {
        $structure = $this->seedStructure(['Standup M-Set Blueprint Copy Accelerator II', 'Standup M-Set Laboratory Optimization II']);

        $this->migrateUp();

        self::assertSame(['Standup M-Set Blueprint Copy Accelerator II'], $this->rigsOf($structure));
    }

    private function seedUser(): string
    {
        $userId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO users (id, created_at, industry_blacklist_group_ids, industry_blacklist_type_ids) VALUES (:id, '2026-10-01 08:00:00', '[]', '[]')",
            ['id' => $userId],
        );

        return $userId;
    }

    /**
     * @param list<string> $rigs
     */
    private function seedStructure(array $rigs): string
    {
        $structureId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO industry_structure_configs (id, name, security_type, structure_type, rigs, is_default, created_at, user_id, is_corporation_structure, is_deleted, hidden_by_users) VALUES (:id, 'Test structure', 'nullsec', 'raitaru', :rigs, false, '2026-10-02 09:00:00', :userId, false, false, '[]')",
            ['id' => $structureId, 'rigs' => json_encode($rigs, JSON_THROW_ON_ERROR), 'userId' => $this->seedUser()],
        );

        return $structureId;
    }

    /**
     * @return list<string>
     */
    private function rigsOf(string $structureId): array
    {
        $rigs = $this->connection->fetchOne('SELECT rigs FROM industry_structure_configs WHERE id = :id', ['id' => $structureId]);
        \assert(\is_string($rigs));

        return json_decode($rigs, true, flags: JSON_THROW_ON_ERROR);
    }

    private function migrateToTheLastMigrationBeforeTheRename(): void
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(
            new ArrayInput([
                'command' => 'doctrine:migrations:migrate',
                'version' => self::LAST_MIGRATION_BEFORE_THE_RENAME,
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
