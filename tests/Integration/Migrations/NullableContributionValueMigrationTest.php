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

#[Group('issue-363')]
final class NullableContributionValueMigrationTest extends IntegrationTestCase
{
    private const string MIGRATION_VERSION = 'Version20261106100000';
    private const string LAST_MIGRATION_BEFORE_THE_VALUE_CHANGE = 'DoctrineMigrations\\Version20261105190000';
    private const string SCHEMA_BEFORE_THE_VALUE_CHANGE = 'contribution_value_before_migration';
    private const float VALUED_CONTRIBUTION_VALUE = 44_000.0;
    private const int VALUED_CONTRIBUTION_QUANTITY = 8_000;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->em->getConnection();
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', self::SCHEMA_BEFORE_THE_VALUE_CHANGE));
        $this->connection->executeStatement(sprintf('CREATE SCHEMA %s', self::SCHEMA_BEFORE_THE_VALUE_CHANGE));
        $this->connection->executeStatement(sprintf('SET search_path TO %s', self::SCHEMA_BEFORE_THE_VALUE_CHANGE));
        $this->migrateToTheLastMigrationBeforeTheValueChange();
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('SET search_path TO public');
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', self::SCHEMA_BEFORE_THE_VALUE_CHANGE));
        parent::tearDown();
    }

    public function testEstimatedValueColumnBecomesNullableAndTheValuedContributionIsKeptUntouched(): void
    {
        $contributionId = $this->seedAContributionWithAValue();
        self::assertSame('NO', $this->nullabilityOfTheEstimatedValue());

        $this->migrateUp();

        self::assertSame('YES', $this->nullabilityOfTheEstimatedValue());
        self::assertSame(
            [self::VALUED_CONTRIBUTION_VALUE, self::VALUED_CONTRIBUTION_QUANTITY, 'approved'],
            [
                (float) $this->connection->fetchOne('SELECT estimated_value FROM industry_project_contributions WHERE id = :id', ['id' => $contributionId]),
                (int) $this->connection->fetchOne('SELECT quantity FROM industry_project_contributions WHERE id = :id', ['id' => $contributionId]),
                $this->connection->fetchOne('SELECT status FROM industry_project_contributions WHERE id = :id', ['id' => $contributionId]),
            ],
        );
    }

    public function testContributionWithAnUnknownValueCanBeStoredAfterTheMigration(): void
    {
        $contributionId = $this->seedAContributionWithAValue();
        $this->migrateUp();

        $this->connection->executeStatement('UPDATE industry_project_contributions SET estimated_value = NULL WHERE id = :id', ['id' => $contributionId]);

        self::assertNull($this->connection->fetchOne('SELECT estimated_value FROM industry_project_contributions WHERE id = :id', ['id' => $contributionId]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM industry_project_contributions'));
    }

    public function testTheMigrationCannotBeReverted(): void
    {
        $this->expectException(IrreversibleMigration::class);

        $this->loadMigration()->down(new Schema());
    }

    private function seedAContributionWithAValue(): string
    {
        $userId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO users (id, created_at, industry_blacklist_group_ids, industry_blacklist_type_ids) VALUES (:id, '2026-10-01 08:00:00', '[]', '[]')",
            ['id' => $userId],
        );
        $projectId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO industry_projects (id, status, short_link_code, created_at, owner_id, blacklist_group_ids, blacklist_type_ids) VALUES (:id, 'in_progress', 'a1b2c3d4e5', '2026-11-01 10:00:00', :ownerId, '[]', '[]')",
            ['id' => $projectId, 'ownerId' => $userId],
        );
        $participantId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO industry_project_participants (id, role, status, joined_at, project_id, user_id) VALUES (:id, 'owner', 'accepted', '2026-11-01 10:05:00', :projectId, :userId)",
            ['id' => $participantId, 'projectId' => $projectId, 'userId' => $userId],
        );
        $bomLineId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            'INSERT INTO industry_project_bom_lines (id, type_id, type_name, required_quantity, fulfilled_quantity, is_job, project_id) VALUES (:id, 34, :typeName, 12000, 0, false, :projectId)',
            ['id' => $bomLineId, 'typeName' => 'Tritanium', 'projectId' => $projectId],
        );
        $contributionId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO industry_project_contributions (id, type, quantity, estimated_value, status, is_auto_detected, is_verified, created_at, project_id, member_id, bom_item_id) VALUES (:id, 'material', :quantity, :value, 'approved', false, true, '2026-11-02 18:00:00', :projectId, :participantId, :bomLineId)",
            [
                'id' => $contributionId,
                'quantity' => self::VALUED_CONTRIBUTION_QUANTITY,
                'value' => self::VALUED_CONTRIBUTION_VALUE,
                'projectId' => $projectId,
                'participantId' => $participantId,
                'bomLineId' => $bomLineId,
            ],
        );

        return $contributionId;
    }

    private function nullabilityOfTheEstimatedValue(): string
    {
        return (string) $this->connection->fetchOne(
            "SELECT is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'industry_project_contributions' AND column_name = 'estimated_value'",
        );
    }

    private function migrateToTheLastMigrationBeforeTheValueChange(): void
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(
            new ArrayInput([
                'command' => 'doctrine:migrations:migrate',
                'version' => self::LAST_MIGRATION_BEFORE_THE_VALUE_CHANGE,
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
