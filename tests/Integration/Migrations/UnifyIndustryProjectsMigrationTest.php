<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Uid\Uuid;

final class UnifyIndustryProjectsMigrationTest extends IntegrationTestCase
{
    private const string MIGRATION_CLASS = 'DoctrineMigrations\\Version20261105150000';
    private const string LAST_MIGRATION_BEFORE_THE_UNIFICATION = 'DoctrineMigrations\\Version20261104110000';
    private const string LEGACY_SCHEMA = 'unify_industry_projects_legacy';

    private const array EMPTIED_TABLES = [
        'industry_projects',
        'industry_project_steps',
        'industry_step_job_matches',
        'industry_step_purchases',
        'group_industry_project_items',
        'group_industry_project_members',
        'group_industry_bom_items',
        'group_industry_contributions',
        'group_industry_sales',
    ];

    private const array KEPT_TABLES = ['users', 'characters', 'cached_wallet_transactions'];

    private const array COLUMNS_OF_THE_UNIFIED_PROJECT = [
        'owner_id',
        'short_link_code',
        'broker_fee_percent',
        'sales_tax_percent',
        'container_name',
        'line_rental_rates_override',
        'blacklist_group_ids',
        'blacklist_type_ids',
        'updated_at',
    ];

    private const array TABLES_OF_THE_PROJECT_CHILDREN = [
        'group_industry_bom_items',
        'group_industry_contributions',
        'group_industry_project_items',
        'group_industry_project_members',
        'group_industry_sales',
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->em->getConnection();
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', self::LEGACY_SCHEMA));
        $this->connection->executeStatement(sprintf('CREATE SCHEMA %s', self::LEGACY_SCHEMA));
        $this->connection->executeStatement(sprintf('SET search_path TO %s', self::LEGACY_SCHEMA));
        $this->migrateTheLegacySchema();
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('SET search_path TO public');
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', self::LEGACY_SCHEMA));
        parent::tearDown();
    }

    public function testEverySoloAndGroupProjectIsDeletedWithItsDependentRowsWhileUsersCharactersAndWalletTransactionsAreKept(): void
    {
        $this->seedThreeSoloProjectsAndTwoGroupProjects();
        self::assertSame(
            [
                'industry_projects' => 3,
                'industry_project_steps' => 4,
                'industry_step_job_matches' => 1,
                'industry_step_purchases' => 1,
                'group_industry_project_items' => 2,
                'group_industry_project_members' => 4,
                'group_industry_bom_items' => 2,
                'group_industry_contributions' => 1,
                'group_industry_sales' => 1,
            ],
            $this->rowCountsOf(self::EMPTIED_TABLES),
        );
        $keptBefore = $this->rowCountsOf(self::KEPT_TABLES);
        self::assertSame(['users' => 2, 'characters' => 2, 'cached_wallet_transactions' => 1], $keptBefore);

        $this->migrateUp();

        self::assertSame(array_fill_keys(self::EMPTIED_TABLES, 0), $this->rowCountsOf(self::EMPTIED_TABLES));
        self::assertSame($keptBefore, $this->rowCountsOf(self::KEPT_TABLES));
    }

    public function testGroupIndustryProjectsTableIsReplacedByTheColumnsOfTheUnifiedIndustryProject(): void
    {
        $this->migrateUp();

        $columns = $this->connection->fetchFirstColumn(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :table',
            ['table' => 'industry_projects'],
        );
        self::assertNull($this->connection->fetchOne('SELECT to_regclass(:table)', ['table' => 'group_industry_projects']));
        self::assertSame([], array_values(array_diff(self::COLUMNS_OF_THE_UNIFIED_PROJECT, $columns)));
        self::assertNotContains('user_id', $columns);
    }

    public function testShortLinkCodeIsUniqueAndEveryProjectChildDeletesWithItsIndustryProject(): void
    {
        $this->migrateUp();

        $uniqueIndexesOnShortLinkCode = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'industry_projects' AND indexdef LIKE 'CREATE UNIQUE INDEX%(short_link_code)'",
        );
        $cascadingChildren = $this->connection->fetchFirstColumn(
            "SELECT conrelid::regclass::text FROM pg_constraint WHERE contype = 'f' AND confdeltype = 'c' AND confrelid = to_regclass('industry_projects') AND conrelid::regclass::text IN ('".implode("','", self::TABLES_OF_THE_PROJECT_CHILDREN)."') ORDER BY 1",
        );
        self::assertSame(1, $uniqueIndexesOnShortLinkCode);
        self::assertSame(self::TABLES_OF_THE_PROJECT_CHILDREN, $cascadingChildren);
    }

    public function testTheMigrationCannotBeReverted(): void
    {
        $this->expectException(IrreversibleMigration::class);

        $this->unifyMigration()->down(new Schema());
    }

    private function seedThreeSoloProjectsAndTwoGroupProjects(): void
    {
        $sabrePilot = $this->insertUserWithCharacter(91_000_101, 'Sabre-Pilot');
        $rifterPilot = $this->insertUserWithCharacter(91_000_102, 'Rifter-Pilot');
        $walletTransactionId = $this->insertWalletTransaction($sabrePilot['characterId']);

        $sabreProject = $this->insertSoloProject($sabrePilot['userId'], 22456, 12, 10, 20, 'active');
        $hurricaneProject = $this->insertSoloProject($sabrePilot['userId'], 24702, 7, 8, 16, 'archived');
        $rifterProject = $this->insertSoloProject($rifterPilot['userId'], 587, 4, 6, 14, 'completed');
        $sabreHullStep = $this->insertStep($sabreProject, 22457, 22456, 0);
        $this->insertStep($sabreProject, 11399, 11400, 1);
        $this->insertStep($hurricaneProject, 24703, 24702, 0);
        $this->insertStep($rifterProject, 588, 587, 0);
        $this->insertJobMatch($sabreHullStep);
        $this->insertPurchase($sabreHullStep, $walletTransactionId);

        $sabreBatch = $this->insertGroupProject($sabrePilot['userId'], 'selling', 'a1b2c3d4e5');
        $rifterBatch = $this->insertGroupProject($rifterPilot['userId'], 'draft', '0f9e8d7c6b');
        $this->insertMembership($sabreBatch, $sabrePilot['userId'], 'owner', 'accepted');
        $rifterMembership = $this->insertMembership($sabreBatch, $rifterPilot['userId'], 'admin', 'accepted');
        $this->insertMembership($sabreBatch, null, 'member', 'removed');
        $this->insertMembership($rifterBatch, $rifterPilot['userId'], 'owner', 'accepted');
        $this->insertGroupItem($sabreBatch, 22456, 'Sabre', 5, 0);
        $this->insertGroupItem($sabreBatch, 24702, 'Hurricane', 3, 1);
        $tritaniumLine = $this->insertBomLine($sabreBatch, 34, 'Tritanium', 20_000);
        $this->insertBomLine($sabreBatch, 35, 'Pyerite', 4_100);
        $this->insertContribution($sabreBatch, $rifterMembership, $tritaniumLine);
        $this->insertSale($sabreBatch, $sabrePilot['userId'], $walletTransactionId);
    }

    private function insertUser(): string
    {
        $userId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO users (id, created_at, industry_blacklist_group_ids, industry_blacklist_type_ids) VALUES (:id, '2026-10-01 08:00:00', '[]', '[]')",
            ['id' => $userId],
        );

        return $userId;
    }

    /**
     * @return array{userId: string, characterId: string}
     */
    private function insertUserWithCharacter(int $eveCharacterId, string $name): array
    {
        $userId = $this->insertUser();
        $characterId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO characters (id, eve_character_id, name, corporation_id, corporation_name, created_at, user_id, auth_status) VALUES (:id, :eveCharacterId, :name, 98000001, 'Test Corp', '2026-10-01 08:00:00', :userId, 'valid')",
            ['id' => $characterId, 'eveCharacterId' => $eveCharacterId, 'name' => $name, 'userId' => $userId],
        );

        return ['userId' => $userId, 'characterId' => $characterId];
    }

    private function insertWalletTransaction(string $characterId): string
    {
        $transactionId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO cached_wallet_transactions (id, character_id, transaction_id, type_id, quantity, unit_price, is_buy, location_id, client_id, date, cached_at) VALUES (:id, :characterId, 7100000001, 22456, 3, 61250000.0, false, 60003760, 90000001, '2026-10-24 21:05:00', '2026-10-24 22:00:00')",
            ['id' => $transactionId, 'characterId' => $characterId],
        );

        return $transactionId;
    }

    private function insertSoloProject(string $userId, int $productTypeId, int $runs, int $meLevel, int $teLevel, string $status): string
    {
        $projectId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO industry_projects (id, product_type_id, runs, me_level, te_level, status, created_at, user_id, excluded_type_ids, personal_use, max_job_duration_days) VALUES (:id, :productTypeId, :runs, :meLevel, :teLevel, :status, '2026-10-20 10:00:00', :userId, '[]', false, 2.0)",
            ['id' => $projectId, 'productTypeId' => $productTypeId, 'runs' => $runs, 'meLevel' => $meLevel, 'teLevel' => $teLevel, 'status' => $status, 'userId' => $userId],
        );

        return $projectId;
    }

    private function insertStep(string $projectId, int $blueprintTypeId, int $productTypeId, int $depth): string
    {
        $stepId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO industry_project_steps (id, blueprint_type_id, product_type_id, quantity, runs, depth, purchased, project_id, activity_type, sort_order, split_index, me_level, te_level, in_stock_quantity, job_match_mode, runs_fixed) VALUES (:id, :blueprintTypeId, :productTypeId, 5, 7, :depth, false, :projectId, 'manufacturing', :depth, 0, 10, 20, 0, 'auto', false)",
            ['id' => $stepId, 'blueprintTypeId' => $blueprintTypeId, 'productTypeId' => $productTypeId, 'depth' => $depth, 'projectId' => $projectId],
        );

        return $stepId;
    }

    private function insertJobMatch(string $stepId): void
    {
        $this->connection->executeStatement(
            "INSERT INTO industry_step_job_matches (id, step_id, esi_job_id, status, runs, matched_at) VALUES (:id, :stepId, 480000123, 'active', 7, '2026-10-21 12:00:00')",
            ['id' => Uuid::v7()->toRfc4122(), 'stepId' => $stepId],
        );
    }

    private function insertPurchase(string $stepId, string $walletTransactionId): void
    {
        $this->connection->executeStatement(
            "INSERT INTO industry_step_purchases (id, step_id, type_id, quantity, unit_price, total_price, source, created_at, transaction_id) VALUES (:id, :stepId, 34, 20000, 5.5, 110000.0, 'market', '2026-10-21 12:30:00', :transactionId)",
            ['id' => Uuid::v7()->toRfc4122(), 'stepId' => $stepId, 'transactionId' => $walletTransactionId],
        );
    }

    private function insertGroupProject(string $ownerId, string $status, string $shortLinkCode): string
    {
        $projectId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO group_industry_projects (id, status, short_link_code, blacklist_group_ids, blacklist_type_ids, broker_fee_percent, sales_tax_percent, created_at, owner_id) VALUES (:id, :status, :shortLinkCode, '[]', '[]', 3.0, 7.5, '2026-10-22 09:00:00', :ownerId)",
            ['id' => $projectId, 'status' => $status, 'shortLinkCode' => $shortLinkCode, 'ownerId' => $ownerId],
        );

        return $projectId;
    }

    private function insertMembership(string $projectId, ?string $userId, string $role, string $status): string
    {
        $membershipId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO group_industry_project_members (id, role, status, joined_at, project_id, user_id) VALUES (:id, :role, :status, '2026-10-22 09:05:00', :projectId, :userId)",
            ['id' => $membershipId, 'role' => $role, 'status' => $status, 'projectId' => $projectId, 'userId' => $userId],
        );

        return $membershipId;
    }

    private function insertGroupItem(string $projectId, int $typeId, string $typeName, int $runs, int $sortOrder): void
    {
        $this->connection->executeStatement(
            'INSERT INTO group_industry_project_items (id, type_id, type_name, me_level, te_level, runs, sort_order, project_id) VALUES (:id, :typeId, :typeName, 10, 20, :runs, :sortOrder, :projectId)',
            ['id' => Uuid::v7()->toRfc4122(), 'typeId' => $typeId, 'typeName' => $typeName, 'runs' => $runs, 'sortOrder' => $sortOrder, 'projectId' => $projectId],
        );
    }

    private function insertBomLine(string $projectId, int $typeId, string $typeName, int $requiredQuantity): string
    {
        $bomLineId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            'INSERT INTO group_industry_bom_items (id, type_id, type_name, required_quantity, fulfilled_quantity, is_job, project_id) VALUES (:id, :typeId, :typeName, :requiredQuantity, 0, false, :projectId)',
            ['id' => $bomLineId, 'typeId' => $typeId, 'typeName' => $typeName, 'requiredQuantity' => $requiredQuantity, 'projectId' => $projectId],
        );

        return $bomLineId;
    }

    private function insertContribution(string $projectId, string $membershipId, string $bomLineId): void
    {
        $this->connection->executeStatement(
            "INSERT INTO group_industry_contributions (id, type, quantity, estimated_value, status, is_auto_detected, is_verified, created_at, project_id, member_id, bom_item_id) VALUES (:id, 'material', 8000, 44000.0, 'approved', false, true, '2026-10-23 18:00:00', :projectId, :membershipId, :bomLineId)",
            ['id' => Uuid::v7()->toRfc4122(), 'projectId' => $projectId, 'membershipId' => $membershipId, 'bomLineId' => $bomLineId],
        );
    }

    private function insertSale(string $projectId, string $recordedById, string $walletTransactionId): void
    {
        $this->connection->executeStatement(
            "INSERT INTO group_industry_sales (id, type_id, type_name, quantity, unit_price, total_price, sold_at, created_at, project_id, recorded_by_id, wallet_transaction_id) VALUES (:id, 22456, 'Sabre', 3, 61250000.0, 183750000.0, '2026-10-24 21:05:00', '2026-10-24 21:10:00', :projectId, :recordedById, :walletTransactionId)",
            ['id' => Uuid::v7()->toRfc4122(), 'projectId' => $projectId, 'recordedById' => $recordedById, 'walletTransactionId' => $walletTransactionId],
        );
    }

    /**
     * @param list<string> $tables
     *
     * @return array<string, int>
     */
    private function rowCountsOf(array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
        }

        return $counts;
    }

    private function migrateTheLegacySchema(): void
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(
            new ArrayInput([
                'command' => 'doctrine:migrations:migrate',
                'version' => self::LAST_MIGRATION_BEFORE_THE_UNIFICATION,
                '--no-interaction' => true,
            ]),
            $output,
        );

        self::assertSame(0, $exitCode, $output->fetch());
    }

    private function migrateUp(): void
    {
        $migration = $this->unifyMigration();
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function unifyMigration(): AbstractMigration
    {
        require_once self::$kernel->getProjectDir().'/migrations/Version20261105150000.php';
        $migrationClass = self::MIGRATION_CLASS;
        $migration = new $migrationClass($this->connection, new NullLogger());
        \assert($migration instanceof AbstractMigration);

        return $migration;
    }
}
