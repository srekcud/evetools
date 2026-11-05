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

#[Group('issue-362')]
final class DropIndustryProjectStepsMigrationTest extends IntegrationTestCase
{
    private const string MIGRATION_VERSION = 'Version20261105190000';
    private const string LAST_MIGRATION_BEFORE_THE_DROP = 'DoctrineMigrations\\Version20261105180000';
    private const string SCHEMA_BEFORE_THE_DROP = 'project_steps_before_migration';

    private const array STEP_TABLES = ['industry_project_steps', 'industry_step_purchases', 'industry_step_job_matches'];

    private const array COLUMNS_OF_THE_SOLO_PROJECT = [
        'product_type_id',
        'runs',
        'me_level',
        'te_level',
        'max_job_duration_days',
        'personal_use',
        'bpo_cost',
        'material_cost',
        'transport_cost',
        'tax_amount',
        'sell_price',
        'excluded_type_ids',
        'invention_materials',
        'estimated_job_cost',
        'estimated_material_cost',
        'estimated_sell_price',
        'estimated_sell_price_source',
        'estimated_tax_amount',
        'jobs_start_date',
    ];

    private const array COLUMNS_OF_THE_PROJECT_AFTER_THE_DROP = [
        'blacklist_group_ids',
        'blacklist_type_ids',
        'broker_fee_percent',
        'completed_at',
        'container_name',
        'created_at',
        'id',
        'line_rental_rates_override',
        'name',
        'notes',
        'owner_id',
        'private',
        'sales_tax_percent',
        'short_link_code',
        'status',
        'updated_at',
    ];

    private const array KEPT_TABLES = [
        'industry_projects' => 1,
        'industry_project_products' => 2,
        'industry_project_participants' => 2,
        'industry_project_bom_lines' => 2,
        'industry_project_contributions' => 1,
        'industry_project_job_matches' => 1,
        'industry_project_purchases' => 1,
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->em->getConnection();
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', self::SCHEMA_BEFORE_THE_DROP));
        $this->connection->executeStatement(sprintf('CREATE SCHEMA %s', self::SCHEMA_BEFORE_THE_DROP));
        $this->connection->executeStatement(sprintf('SET search_path TO %s', self::SCHEMA_BEFORE_THE_DROP));
        $this->migrateToTheLastMigrationBeforeTheDrop();
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('SET search_path TO public');
        $this->connection->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', self::SCHEMA_BEFORE_THE_DROP));
        parent::tearDown();
    }

    public function testThreeStepTablesAreDroppedWithTheirRows(): void
    {
        $this->seedProjectWithItsChildrenAndOneRowInEachStepTable();
        self::assertSame(
            ['industry_project_steps' => 1, 'industry_step_purchases' => 1, 'industry_step_job_matches' => 1],
            $this->rowCountsOf(self::STEP_TABLES),
        );

        $this->migrateUp();

        self::assertSame(
            ['industry_project_steps' => null, 'industry_step_purchases' => null, 'industry_step_job_matches' => null],
            array_combine(
                self::STEP_TABLES,
                array_map(fn (string $table): mixed => $this->connection->fetchOne('SELECT to_regclass(:table)', ['table' => $table]) ?: null, self::STEP_TABLES),
            ),
        );
    }

    public function testProjectKeepsItsProductsParticipantsBomLinesContributionsAndLinks(): void
    {
        $projectId = $this->seedProjectWithItsChildrenAndOneRowInEachStepTable();

        $this->migrateUp();

        self::assertSame(self::KEPT_TABLES, $this->rowCountsOf(array_keys(self::KEPT_TABLES)));
        self::assertSame(
            ['a1b2c3d4e5', 'in_progress', 'Ishtar fleet'],
            array_values($this->connection->fetchAssociative('SELECT short_link_code, status, name FROM industry_projects WHERE id = :id', ['id' => $projectId]) ?: []),
        );
    }

    public function testColumnsOfTheSoloProjectAreDropped(): void
    {
        $this->migrateUp();

        $columns = $this->columnsOf('industry_projects');
        self::assertSame([], array_values(array_intersect(self::COLUMNS_OF_THE_SOLO_PROJECT, $columns)));
        self::assertSame(self::COLUMNS_OF_THE_PROJECT_AFTER_THE_DROP, $columns);
    }

    public function testExistingProjectBecomesPrivateAndNewProjectsArePrivateByDefault(): void
    {
        $projectId = $this->seedProjectWithItsChildrenAndOneRowInEachStepTable();

        $this->migrateUp();
        $newProjectId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO industry_projects (id, status, short_link_code, created_at, owner_id, blacklist_group_ids, blacklist_type_ids) VALUES (:id, 'draft', 'f6e5d4c3b2', '2026-11-03 10:00:00', (SELECT owner_id FROM industry_projects WHERE id = :existingId), '[]', '[]')",
            ['id' => $newProjectId, 'existingId' => $projectId],
        );

        self::assertSame(
            [true, true],
            [
                (bool) $this->connection->fetchOne('SELECT private FROM industry_projects WHERE id = :id', ['id' => $projectId]),
                (bool) $this->connection->fetchOne('SELECT private FROM industry_projects WHERE id = :id', ['id' => $newProjectId]),
            ],
        );
    }

    public function testTheMigrationCannotBeReverted(): void
    {
        $this->expectException(IrreversibleMigration::class);

        $this->loadMigration()->down(new Schema());
    }

    private function seedProjectWithItsChildrenAndOneRowInEachStepTable(): string
    {
        $owner = $this->insertUserWithCharacter(91_362_101, 'Aria Solberg');
        $partner = $this->insertUserWithCharacter(91_362_102, 'Bram Voss');
        $walletTransactionId = $this->insertWalletTransaction($owner['characterId']);

        $projectId = $this->insertProject($owner['userId'], 'a1b2c3d4e5');
        $this->insertProduct($projectId, 12005, 'Ishtar', 5, 0);
        $this->insertProduct($projectId, 12003, 'Zealot', 3, 1);
        $ownerParticipantId = $this->insertParticipant($projectId, $owner['userId'], 'owner');
        $this->insertParticipant($projectId, $partner['userId'], 'member');
        $ishtarLineId = $this->insertBomLine($projectId, 12005, 'Ishtar', 5, true);
        $tritaniumLineId = $this->insertBomLine($projectId, 34, 'Tritanium', 12_000, false);
        $this->insertContribution($projectId, $ownerParticipantId, $tritaniumLineId);
        $this->insertJobMatchOfTheBomLine($ishtarLineId);
        $this->insertPurchaseOfTheBomLine($tritaniumLineId);

        $stepId = $this->insertStep($projectId);
        $this->insertStepJobMatch($stepId);
        $this->insertStepPurchase($stepId, $walletTransactionId);

        return $projectId;
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

    private function insertWalletTransaction(string $characterId): string
    {
        $transactionId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO cached_wallet_transactions (id, character_id, transaction_id, type_id, quantity, unit_price, is_buy, location_id, client_id, date, cached_at) VALUES (:id, :characterId, 8362000101, 34, 12000, 5.5, true, 60003760, 90000001, '2026-11-02 09:00:00', '2026-11-02 10:00:00')",
            ['id' => $transactionId, 'characterId' => $characterId],
        );

        return $transactionId;
    }

    private function insertProject(string $ownerId, string $shortLinkCode): string
    {
        $projectId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO industry_projects (id, name, product_type_id, runs, me_level, te_level, status, created_at, owner_id, excluded_type_ids, personal_use, bpo_cost, material_cost, transport_cost, tax_amount, sell_price, invention_materials, estimated_job_cost, estimated_material_cost, estimated_sell_price, estimated_sell_price_source, estimated_tax_amount, jobs_start_date, max_job_duration_days, short_link_code, blacklist_group_ids, blacklist_type_ids) VALUES (:id, 'Ishtar fleet', 12005, 5, 10, 20, 'in_progress', '2026-11-01 10:00:00', :ownerId, '[34]', true, 1500000.0, 62000000.0, 450000.0, 3100000.0, 98000000.0, '[]', 4200000.0, 61000000.0, 97000000.0, 'jita', 3000000.0, '2026-11-01 11:00:00', 2.0, :shortLinkCode, '[]', '[]')",
            ['id' => $projectId, 'ownerId' => $ownerId, 'shortLinkCode' => $shortLinkCode],
        );

        return $projectId;
    }

    private function insertProduct(string $projectId, int $typeId, string $typeName, int $runs, int $sortOrder): void
    {
        $this->connection->executeStatement(
            'INSERT INTO industry_project_products (id, type_id, type_name, me_level, te_level, runs, sort_order, project_id) VALUES (:id, :typeId, :typeName, 10, 20, :runs, :sortOrder, :projectId)',
            ['id' => Uuid::v7()->toRfc4122(), 'typeId' => $typeId, 'typeName' => $typeName, 'runs' => $runs, 'sortOrder' => $sortOrder, 'projectId' => $projectId],
        );
    }

    private function insertParticipant(string $projectId, string $userId, string $role): string
    {
        $participantId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO industry_project_participants (id, role, status, joined_at, project_id, user_id) VALUES (:id, :role, 'accepted', '2026-11-01 10:05:00', :projectId, :userId)",
            ['id' => $participantId, 'role' => $role, 'projectId' => $projectId, 'userId' => $userId],
        );

        return $participantId;
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

    private function insertContribution(string $projectId, string $participantId, string $bomLineId): void
    {
        $this->connection->executeStatement(
            "INSERT INTO industry_project_contributions (id, type, quantity, estimated_value, status, is_auto_detected, is_verified, created_at, project_id, member_id, bom_item_id) VALUES (:id, 'material', 8000, 44000.0, 'approved', false, true, '2026-11-02 18:00:00', :projectId, :participantId, :bomLineId)",
            ['id' => Uuid::v7()->toRfc4122(), 'projectId' => $projectId, 'participantId' => $participantId, 'bomLineId' => $bomLineId],
        );
    }

    private function insertJobMatchOfTheBomLine(string $bomLineId): void
    {
        $this->connection->executeStatement(
            "INSERT INTO industry_project_job_matches (id, bom_line_id, esi_job_id, status, runs, manual, matched_at) VALUES (:id, :bomLineId, 7362801, 'active', 3, false, '2026-11-02 12:00:00')",
            ['id' => Uuid::v7()->toRfc4122(), 'bomLineId' => $bomLineId],
        );
    }

    private function insertPurchaseOfTheBomLine(string $bomLineId): void
    {
        $this->connection->executeStatement(
            "INSERT INTO industry_project_purchases (id, bom_line_id, transaction_id, type_id, quantity, unit_price, total_price, source, created_at) VALUES (:id, :bomLineId, NULL, 34, 50, 5.5, 275.0, 'manual', '2026-11-02 12:30:00')",
            ['id' => Uuid::v7()->toRfc4122(), 'bomLineId' => $bomLineId],
        );
    }

    private function insertStep(string $projectId): string
    {
        $stepId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO industry_project_steps (id, blueprint_type_id, product_type_id, quantity, runs, depth, purchased, project_id, activity_type, sort_order, split_index, me_level, te_level, in_stock_quantity, job_match_mode, runs_fixed) VALUES (:id, 12006, 12005, 5, 5, 0, false, :projectId, 'manufacturing', 0, 0, 10, 20, 0, 'auto', false)",
            ['id' => $stepId, 'projectId' => $projectId],
        );

        return $stepId;
    }

    private function insertStepJobMatch(string $stepId): void
    {
        $this->connection->executeStatement(
            "INSERT INTO industry_step_job_matches (id, step_id, esi_job_id, status, runs, matched_at) VALUES (:id, :stepId, 480000123, 'active', 5, '2026-11-01 12:00:00')",
            ['id' => Uuid::v7()->toRfc4122(), 'stepId' => $stepId],
        );
    }

    private function insertStepPurchase(string $stepId, string $walletTransactionId): void
    {
        $this->connection->executeStatement(
            "INSERT INTO industry_step_purchases (id, step_id, type_id, quantity, unit_price, total_price, source, created_at, transaction_id) VALUES (:id, :stepId, 34, 12000, 5.5, 66000.0, 'esi_wallet', '2026-11-02 12:30:00', :transactionId)",
            ['id' => Uuid::v7()->toRfc4122(), 'stepId' => $stepId, 'transactionId' => $walletTransactionId],
        );
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

    private function migrateToTheLastMigrationBeforeTheDrop(): void
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(
            new ArrayInput([
                'command' => 'doctrine:migrations:migrate',
                'version' => self::LAST_MIGRATION_BEFORE_THE_DROP,
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
