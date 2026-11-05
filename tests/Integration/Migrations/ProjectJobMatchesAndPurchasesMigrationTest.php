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

#[Group('issue-362')]
final class ProjectJobMatchesAndPurchasesMigrationTest extends IntegrationTestCase
{
    private const string MIGRATION_VERSION = 'Version20261105180000';
    private const string LAST_MIGRATION_BEFORE_THE_LINKS = 'DoctrineMigrations\\Version20261105160000';
    private const string SCHEMA_BEFORE_THE_LINKS = 'project_links_before_migration';

    private const array JOB_MATCH_COLUMNS = [
        'bom_line_id',
        'character_name',
        'cost',
        'end_date',
        'esi_job_id',
        'facility_id',
        'facility_name',
        'id',
        'manual',
        'matched_at',
        'runs',
        'status',
    ];

    private const array PURCHASE_COLUMNS = [
        'bom_line_id',
        'created_at',
        'id',
        'quantity',
        'source',
        'total_price',
        'transaction_id',
        'type_id',
        'unit_price',
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->em->getConnection();
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', self::SCHEMA_BEFORE_THE_LINKS));
        $this->connection->executeStatement(sprintf('CREATE SCHEMA %s', self::SCHEMA_BEFORE_THE_LINKS));
        $this->connection->executeStatement(sprintf('SET search_path TO %s', self::SCHEMA_BEFORE_THE_LINKS));
        $this->migrateToTheLastMigrationBeforeTheLinks();
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('SET search_path TO public');
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', self::SCHEMA_BEFORE_THE_LINKS));
        parent::tearDown();
    }

    public function testJobMatchAndPurchaseTablesAreCreatedWithTheColumnsOfTheLinks(): void
    {
        $this->migrateUp();

        self::assertSame(
            ['industry_project_job_matches' => self::JOB_MATCH_COLUMNS, 'industry_project_purchases' => self::PURCHASE_COLUMNS],
            ['industry_project_job_matches' => $this->columnsOf('industry_project_job_matches'), 'industry_project_purchases' => $this->columnsOf('industry_project_purchases')],
        );
    }

    public function testJobMatchesAreIndexedByBomLineAndByEsiJobWithoutUniqueness(): void
    {
        $this->migrateUp();

        $indexes = $this->connection->fetchAllAssociative(
            "SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'industry_project_job_matches' AND indexdef NOT LIKE '%(id)'",
        );
        $definitions = array_column($indexes, 'indexdef');
        sort($definitions);

        self::assertCount(2, $definitions);
        self::assertSame(
            [[false, '(bom_line_id)'], [false, '(esi_job_id)']],
            array_map(
                static fn (string $definition): array => [str_starts_with($definition, 'CREATE UNIQUE'), substr($definition, (int) strrpos($definition, '('))],
                $definitions,
            ),
        );
    }

    public function testDeletingABomLineDeletesItsJobMatchesAndPurchasesAndKeepsThoseOfTheOtherLine(): void
    {
        $owner = $this->insertUserWithCharacter(91_362_001, 'Aria Solberg');
        $projectId = $this->insertProject($owner['userId'], 'a1b2c3d4e5');
        $ishtarLineId = $this->insertBomLine($projectId, 12005, 'Ishtar', 5, true);
        $tritaniumLineId = $this->insertBomLine($projectId, 34, 'Tritanium', 12_000, false);
        $this->migrateUp();
        $this->insertJobMatch($ishtarLineId, 7_362_801);
        $this->insertPurchase($ishtarLineId, 12005, null);
        $this->insertPurchase($tritaniumLineId, 34, null);

        $this->connection->executeStatement('DELETE FROM industry_project_bom_lines WHERE id = :id', ['id' => $ishtarLineId]);

        self::assertSame(
            ['industry_project_job_matches' => 0, 'industry_project_purchases' => 1],
            $this->rowCountsOf(['industry_project_job_matches', 'industry_project_purchases']),
        );
    }

    public function testDeletingAWalletTransactionKeepsItsPurchaseWithoutTransaction(): void
    {
        $owner = $this->insertUserWithCharacter(91_362_002, 'Aria Alt');
        $projectId = $this->insertProject($owner['userId'], 'f6e5d4c3b2');
        $ramLineId = $this->insertBomLine($projectId, 11478, 'R.A.M.- Starship Tech', 400, true);
        $walletTransactionId = $this->insertWalletTransaction($owner['characterId']);
        $this->migrateUp();
        $purchaseId = $this->insertPurchase($ramLineId, 11478, $walletTransactionId);

        $this->connection->executeStatement('DELETE FROM cached_wallet_transactions WHERE id = :id', ['id' => $walletTransactionId]);

        self::assertSame(
            [$purchaseId, null, 50],
            array_values($this->connection->fetchAssociative('SELECT id, transaction_id, quantity FROM industry_project_purchases') ?: []),
        );
    }

    private function insertUserWithCharacter(int $eveCharacterId, string $name): array
    {
        $userId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO users (id, created_at, industry_blacklist_group_ids, industry_blacklist_type_ids) VALUES (:id, '2026-10-01 08:00:00', '[]', '[]')",
            ['id' => $userId],
        );
        $characterId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO characters (id, eve_character_id, name, corporation_id, corporation_name, created_at, user_id, auth_status) VALUES (:id, :eveCharacterId, :name, 98000001, 'Test Corp', '2026-10-01 08:00:00', :userId, 'valid')",
            ['id' => $characterId, 'eveCharacterId' => $eveCharacterId, 'name' => $name, 'userId' => $userId],
        );

        return ['userId' => $userId, 'characterId' => $characterId];
    }

    private function insertProject(string $ownerId, string $shortLinkCode): string
    {
        $projectId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO industry_projects (id, product_type_id, runs, me_level, te_level, status, created_at, owner_id, excluded_type_ids, personal_use, max_job_duration_days, short_link_code, blacklist_group_ids, blacklist_type_ids) VALUES (:id, 12005, 5, 10, 20, 'in_progress', '2026-11-01 10:00:00', :ownerId, '[]', false, 2.0, :shortLinkCode, '[]', '[]')",
            ['id' => $projectId, 'ownerId' => $ownerId, 'shortLinkCode' => $shortLinkCode],
        );

        return $projectId;
    }

    private function insertBomLine(string $projectId, int $typeId, string $typeName, int $requiredQuantity, bool $isJob): string
    {
        $lineId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            'INSERT INTO industry_project_bom_lines (id, type_id, type_name, required_quantity, fulfilled_quantity, is_job, project_id) VALUES (:id, :typeId, :typeName, :requiredQuantity, 0, :isJob, :projectId)',
            ['id' => $lineId, 'typeId' => $typeId, 'typeName' => $typeName, 'requiredQuantity' => $requiredQuantity, 'isJob' => $isJob ? 'true' : 'false', 'projectId' => $projectId],
        );

        return $lineId;
    }

    private function insertWalletTransaction(string $characterId): string
    {
        $transactionId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO cached_wallet_transactions (id, character_id, transaction_id, type_id, quantity, unit_price, is_buy, location_id, client_id, date, cached_at) VALUES (:id, :characterId, 8362000001, 11478, 50, 3040.5, true, 60003760, 90000001, '2026-11-02 09:00:00', '2026-11-02 10:00:00')",
            ['id' => $transactionId, 'characterId' => $characterId],
        );

        return $transactionId;
    }

    private function insertJobMatch(string $bomLineId, int $esiJobId): void
    {
        $this->connection->executeStatement(
            "INSERT INTO industry_project_job_matches (id, bom_line_id, esi_job_id, status, runs, manual, matched_at) VALUES (:id, :bomLineId, :esiJobId, 'active', 3, false, '2026-11-02 12:00:00')",
            ['id' => Uuid::v7()->toRfc4122(), 'bomLineId' => $bomLineId, 'esiJobId' => $esiJobId],
        );
    }

    private function insertPurchase(string $bomLineId, int $typeId, ?string $walletTransactionId): string
    {
        $purchaseId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO industry_project_purchases (id, bom_line_id, transaction_id, type_id, quantity, unit_price, total_price, source, created_at) VALUES (:id, :bomLineId, :transactionId, :typeId, 50, 3040.5, 152025.0, 'esi_wallet', '2026-11-02 12:30:00')",
            ['id' => $purchaseId, 'bomLineId' => $bomLineId, 'transactionId' => $walletTransactionId, 'typeId' => $typeId],
        );

        return $purchaseId;
    }

    private function columnsOf(string $table): array
    {
        $columns = $this->connection->fetchFirstColumn(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :table ORDER BY column_name',
            ['table' => $table],
        );

        return array_values(array_map('strval', $columns));
    }

    private function rowCountsOf(array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
        }

        return $counts;
    }

    private function migrateToTheLastMigrationBeforeTheLinks(): void
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(
            new ArrayInput([
                'command' => 'doctrine:migrations:migrate',
                'version' => self::LAST_MIGRATION_BEFORE_THE_LINKS,
                '--no-interaction' => true,
            ]),
            $output,
        );

        self::assertSame(0, $exitCode, $output->fetch());
    }

    private function migrateUp(): void
    {
        $migrationFile = self::$kernel->getProjectDir().'/migrations/'.self::MIGRATION_VERSION.'.php';
        self::assertFileExists($migrationFile);
        require_once $migrationFile;
        $migrationClass = 'DoctrineMigrations\\'.self::MIGRATION_VERSION;
        $migration = new $migrationClass($this->connection, new NullLogger());
        \assert($migration instanceof AbstractMigration);
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
