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

#[Group('issue-533')]
final class EscalationIncomeMigrationTest extends IntegrationTestCase
{
    private const string MIGRATION_VERSION = 'Version20261110040000';
    private const string LAST_MIGRATION_BEFORE_THE_ESCALATION_INCOME = 'DoctrineMigrations\\Version20261110030000';
    private const string SCHEMA_BEFORE_THE_ESCALATION_INCOME = 'escalation_income_before_migration';
    private const string SOLD_UPDATED_AT = '2026-10-20 16:05:00';
    private const string ZERO_PRICE_UPDATED_AT = '2026-10-25 08:30:00';
    private const string ON_SALE_UPDATED_AT = '2026-11-01 19:45:00';

    private Connection $connection;
    private string $pilotId;
    private string $soldEscalationId;
    private string $zeroPriceEscalationId;
    private string $onSaleEscalationId;
    private string $lootSaleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->em->getConnection();
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', self::SCHEMA_BEFORE_THE_ESCALATION_INCOME));
        $this->connection->executeStatement(sprintf('CREATE SCHEMA %s', self::SCHEMA_BEFORE_THE_ESCALATION_INCOME));
        $this->connection->executeStatement(sprintf('SET search_path TO %s', self::SCHEMA_BEFORE_THE_ESCALATION_INCOME));
        $this->migrateToTheLastMigrationBeforeTheEscalationIncome();

        $this->pilotId = $this->seedUser();
        $this->soldEscalationId = $this->seedEscalation($this->pilotId, 'vendu', 100_000_000, 'Blood Raider Hideaway', 'Aldranette', self::SOLD_UPDATED_AT);
        $this->zeroPriceEscalationId = $this->seedEscalation($this->pilotId, 'vendu', 0, 'Guristas Den', 'Tamo', self::ZERO_PRICE_UPDATED_AT);
        $this->onSaleEscalationId = $this->seedEscalation($this->pilotId, 'envente', 55_000_000, 'Serpentis Lair', 'Jita', self::ON_SALE_UPDATED_AT);
        $this->lootSaleId = $this->seedLootSale($this->pilotId);
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('SET search_path TO public');
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', self::SCHEMA_BEFORE_THE_ESCALATION_INCOME));
        parent::tearDown();
    }

    public function testUpGivesEachSoldEscalationASoldAtEqualToItsLastUpdateAndLeavesTheOthersWithoutOne(): void
    {
        $this->migrateUp();

        self::assertSame(
            [self::SOLD_UPDATED_AT, self::ZERO_PRICE_UPDATED_AT, null],
            [
                $this->soldAtOf($this->soldEscalationId),
                $this->soldAtOf($this->zeroPriceEscalationId),
                $this->soldAtOf($this->onSaleEscalationId),
            ],
        );
    }

    public function testUpCreatesOneIncomeForEachSoldEscalationWithItsPriceItsSaleDateAndItsSystemDescription(): void
    {
        $this->migrateUp();

        self::assertSame(
            [
                [$this->soldEscalationId, 100_000_000.0, self::SOLD_UPDATED_AT, 'Blood Raider Hideaway - Aldranette', $this->pilotId],
                [$this->zeroPriceEscalationId, 0.0, self::ZERO_PRICE_UPDATED_AT, 'Guristas Den - Tamo', $this->pilotId],
            ],
            array_map(
                static fn (array $row): array => [$row['escalation_id'], (float) $row['amount'], $row['date'], $row['description'], $row['user_id']],
                $this->connection->fetchAllAssociative("SELECT escalation_id, amount, to_char(date, 'YYYY-MM-DD HH24:MI:SS') AS date, description, user_id FROM pve_income WHERE type = 'escalation' ORDER BY date"),
            ),
        );
    }

    public function testUpLeavesTheExistingLootSaleIncomeUntouchedAndWithoutEscalationLink(): void
    {
        $this->migrateUp();

        $lootSale = $this->connection->fetchAssociative('SELECT type, amount, escalation_id FROM pve_income WHERE id = :id', ['id' => $this->lootSaleId]);
        self::assertIsArray($lootSale);
        self::assertSame(['loot_sale', 85_000.0, null], [$lootSale['type'], (float) $lootSale['amount'], $lootSale['escalation_id']]);
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pve_income'));
    }

    public function testDeletingAnEscalationAfterUpKeepsItsIncomeAndEmptiesItsLink(): void
    {
        $this->migrateUp();

        $this->connection->executeStatement('DELETE FROM escalations WHERE id = :id', ['id' => $this->soldEscalationId]);

        $income = $this->connection->fetchAssociative("SELECT amount, escalation_id FROM pve_income WHERE type = 'escalation' AND description = 'Blood Raider Hideaway - Aldranette'");
        self::assertIsArray($income);
        self::assertSame([100_000_000.0, null], [(float) $income['amount'], $income['escalation_id']]);
    }

    public function testAfterUpAnEscalationCannotHaveTwoIncomes(): void
    {
        $this->migrateUp();

        $this->connection->executeStatement('SAVEPOINT duplicate_income');
        try {
            $this->connection->executeStatement(
                "INSERT INTO pve_income (id, user_id, type, description, amount, date, created_at, escalation_id) VALUES (:id, :userId, 'escalation', 'Duplicate', 1, '2026-11-02 10:00:00', '2026-11-02 10:00:00', :escalationId)",
                ['id' => Uuid::v7()->toRfc4122(), 'userId' => $this->pilotId, 'escalationId' => $this->soldEscalationId],
            );
            self::fail('A second income for the same escalation must violate the unique constraint');
        } catch (UniqueConstraintViolationException) {
            $this->connection->executeStatement('ROLLBACK TO SAVEPOINT duplicate_income');
        }

        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pve_income WHERE type = 'escalation'"));
    }

    public function testDownDeletesTheEscalationIncomesThenDropsBothColumnsAndKeepsTheOtherIncomes(): void
    {
        $this->migrateUp();
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pve_income WHERE type = 'escalation'"));

        $this->migrateDown();

        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pve_income WHERE type = 'escalation'"));
        self::assertSame([$this->lootSaleId], $this->connection->fetchFirstColumn('SELECT id FROM pve_income'));
        self::assertSame([], $this->columnsNamed('escalations', 'sold_at'));
        self::assertSame([], $this->columnsNamed('pve_income', 'escalation_id'));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM escalations'));
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

    private function seedEscalation(string $userId, string $saleStatus, int $price, string $type, string $solarSystemName, string $updatedAt): string
    {
        $escalationId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO escalations (id, user_id, character_id, character_name, type, solar_system_id, solar_system_name, security_status, price, visibility, bm_status, sale_status, corporation_id, expires_at, created_at, updated_at) VALUES (:id, :userId, 2112533001, 'Ilse Varn', :type, 30002813, :solarSystemName, -0.4, :price, 'corp', 'nouveau', :saleStatus, 98533001, '2026-11-20 00:00:00', '2026-10-15 09:00:00', :updatedAt)",
            ['id' => $escalationId, 'userId' => $userId, 'type' => $type, 'solarSystemName' => $solarSystemName, 'price' => $price, 'saleStatus' => $saleStatus, 'updatedAt' => $updatedAt],
        );

        return $escalationId;
    }

    private function seedLootSale(string $userId): string
    {
        $incomeId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO pve_income (id, user_id, type, description, amount, date, created_at) VALUES (:id, :userId, 'loot_sale', '6x Rogue Drone Infestation Data', 85000, '2026-10-28 12:00:00', '2026-10-28 12:00:00')",
            ['id' => $incomeId, 'userId' => $userId],
        );

        return $incomeId;
    }

    private function soldAtOf(string $escalationId): ?string
    {
        $soldAt = $this->connection->fetchOne("SELECT to_char(sold_at, 'YYYY-MM-DD HH24:MI:SS') FROM escalations WHERE id = :id", ['id' => $escalationId]);

        return $soldAt === false ? null : $soldAt;
    }

    private function columnsNamed(string $table, string $column): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = :schema AND table_name = :table AND column_name = :column',
            ['schema' => self::SCHEMA_BEFORE_THE_ESCALATION_INCOME, 'table' => $table, 'column' => $column],
        );
    }

    private function migrateToTheLastMigrationBeforeTheEscalationIncome(): void
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(
            new ArrayInput([
                'command' => 'doctrine:migrations:migrate',
                'version' => self::LAST_MIGRATION_BEFORE_THE_ESCALATION_INCOME,
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
        $this->runQueriesOf($migration);
        self::assertSame(['sold_at'], $this->columnsNamed('escalations', 'sold_at'));
        self::assertSame(['escalation_id'], $this->columnsNamed('pve_income', 'escalation_id'));
    }

    private function migrateDown(): void
    {
        $migration = $this->loadMigration();
        $migration->down(new Schema());
        $this->runQueriesOf($migration);
    }

    private function runQueriesOf(AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
