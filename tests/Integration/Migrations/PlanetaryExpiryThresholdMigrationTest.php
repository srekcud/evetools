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

#[Group('issue-537')]
final class PlanetaryExpiryThresholdMigrationTest extends IntegrationTestCase
{
    private const string MIGRATION_VERSION = 'Version20261110030000';
    private const string LAST_MIGRATION_BEFORE_THE_THRESHOLD = 'DoctrineMigrations\\Version20261110020000';
    private const string SCHEMA_BEFORE_THE_THRESHOLD = 'planetary_threshold_before_migration';

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->em->getConnection();
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', self::SCHEMA_BEFORE_THE_THRESHOLD));
        $this->connection->executeStatement(sprintf('CREATE SCHEMA %s', self::SCHEMA_BEFORE_THE_THRESHOLD));
        $this->connection->executeStatement(sprintf('SET search_path TO %s', self::SCHEMA_BEFORE_THE_THRESHOLD));
        $this->migrateToTheLastMigrationBeforeTheThreshold();
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('SET search_path TO public');
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', self::SCHEMA_BEFORE_THE_THRESHOLD));
        parent::tearDown();
    }

    public function testPlanetaryPreferencesAtTheOldDefaultMoveToOneDayWhileOtherValuesAndCategoriesAreKept(): void
    {
        $pilotWithOldDefault = $this->seedUser();
        $pilotWithOwnThreshold = $this->seedUser();
        $pilotWithoutThreshold = $this->seedUser();
        $oldDefaultPlanetary = $this->seedPreference($pilotWithOldDefault, 'planetary', 120);
        $ownThresholdPlanetary = $this->seedPreference($pilotWithOwnThreshold, 'planetary', 360);
        $noThresholdPlanetary = $this->seedPreference($pilotWithoutThreshold, 'planetary', null);
        $industryAtOldDefault = $this->seedPreference($pilotWithOldDefault, 'industry', 120);

        $this->migrateUp();

        self::assertSame(
            [1440, 360, null, 120],
            [
                $this->thresholdOf($oldDefaultPlanetary),
                $this->thresholdOf($ownThresholdPlanetary),
                $this->thresholdOf($noThresholdPlanetary),
                $this->thresholdOf($industryAtOldDefault),
            ],
        );
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

    private function seedPreference(string $userId, string $category, ?int $thresholdMinutes): string
    {
        $preferenceId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO user_notification_preferences (id, category, enabled, threshold_minutes, push_enabled, created_at, updated_at, user_id) VALUES (:id, :category, true, :threshold, false, '2026-10-02 09:00:00', '2026-10-02 09:00:00', :userId)",
            ['id' => $preferenceId, 'category' => $category, 'threshold' => $thresholdMinutes, 'userId' => $userId],
        );

        return $preferenceId;
    }

    private function thresholdOf(string $preferenceId): ?int
    {
        $threshold = $this->connection->fetchOne('SELECT threshold_minutes FROM user_notification_preferences WHERE id = :id', ['id' => $preferenceId]);

        return $threshold === null ? null : (int) $threshold;
    }

    private function migrateToTheLastMigrationBeforeTheThreshold(): void
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(
            new ArrayInput([
                'command' => 'doctrine:migrations:migrate',
                'version' => self::LAST_MIGRATION_BEFORE_THE_THRESHOLD,
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
