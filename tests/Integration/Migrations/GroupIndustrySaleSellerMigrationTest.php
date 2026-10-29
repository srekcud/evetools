<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustrySale;
use App\Enum\GroupProjectStatus;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

#[Group('issue-241')]
final class GroupIndustrySaleSellerMigrationTest extends IntegrationTestCase
{
    private const string SELLER_COLUMN = 'seller_character_id';

    private Connection $connection;
    private string $saleId;
    private string $recorderId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->em->getConnection();

        $owner = $this->createUser();
        $owner->setMainCharacter($this->createCharacter($owner, 'O-Main')->setEveCharacterId(2120000001));
        $this->createCharacter($owner, 'O-Alt')->setEveCharacterId(2120000002);
        $project = (new GroupIndustryProject())->setOwner($owner)->setName('Sabre')->setStatus(GroupProjectStatus::Selling);
        $this->em->persist($project);
        $sale = (new GroupIndustrySale())
            ->setTypeId(0)
            ->setTypeName('Sabre')
            ->setQuantity(3)
            ->setUnitPrice(61_250_000.0)
            ->recalculateTotalPrice()
            ->setSoldAt(new \DateTimeImmutable('2026-10-24T21:05:00+00:00'))
            ->setRecordedBy($owner);
        $project->addSale($sale);
        $this->flushAndClear();
        $this->saleId = (string) $sale->getId();
        $this->recorderId = (string) $owner->getId();
    }

    public function testA10SaleRecordedBeforeTheMigrationHasAnUnknownSellerAndKeepsItsRecorderAndAmounts(): void
    {
        $this->migrateDown();

        $this->migrateUp();

        self::assertSame(
            [null, $this->recorderId, 3, 183_750_000.0],
            $this->saleRow(),
        );
    }

    public function testMigrationAddsANullableSellerColumnReferencingTheCharactersAndClearedOnTheirDeletion(): void
    {
        $this->migrateDown();

        $this->migrateUp();

        self::assertSame(
            [['YES'], [['characters', 'id', 'SET NULL']], 1],
            [$this->sellerColumnNullability(), $this->sellerForeignKeys(), $this->sellerIndexesCount()],
        );
    }

    public function testMigrationDownRemovesTheSellerColumnItsForeignKeyAndItsIndexAndKeepsTheSale(): void
    {
        $this->migrateDown();

        self::assertSame(
            [[], [], 0, 1],
            [
                $this->sellerColumnNullability(),
                $this->sellerForeignKeys(),
                $this->sellerIndexesCount(),
                (int) $this->connection->fetchOne('SELECT COUNT(*) FROM group_industry_sales WHERE id = ?', [$this->saleId]),
            ],
        );
    }

    private function migrateUp(): void
    {
        $migration = $this->sellerMigration();
        $migration->up(new Schema());
        $this->execute($migration);
    }

    private function migrateDown(): void
    {
        $migration = $this->sellerMigration();
        $migration->down(new Schema());
        $this->execute($migration);
    }

    private function execute(AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function sellerMigration(): AbstractMigration
    {
        $migrationFiles = array_values(array_filter(
            glob(self::$kernel->getProjectDir() . '/migrations/Version*.php') ?: [],
            static fn (string $migrationFile): bool => str_contains((string) file_get_contents($migrationFile), 'group_industry_sales')
                && str_contains((string) file_get_contents($migrationFile), self::SELLER_COLUMN),
        ));
        self::assertCount(1, $migrationFiles, 'exactly one migration adds group_industry_sales.seller_character_id');

        require_once $migrationFiles[0];
        $migrationClass = 'DoctrineMigrations\\' . basename($migrationFiles[0], '.php');
        $migration = new $migrationClass($this->connection, new NullLogger());
        \assert($migration instanceof AbstractMigration);

        return $migration;
    }

    private function saleRow(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT seller_character_id, recorded_by_id, quantity, total_price FROM group_industry_sales WHERE id = ?',
            [$this->saleId],
        );
        \assert($row !== false);

        return [$row['seller_character_id'], $row['recorded_by_id'], (int) $row['quantity'], (float) $row['total_price']];
    }

    private function sellerColumnNullability(): array
    {
        return $this->connection->fetchFirstColumn(
            "SELECT is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'group_industry_sales' AND column_name = ?",
            [self::SELLER_COLUMN],
        );
    }

    private function sellerForeignKeys(): array
    {
        return $this->connection->fetchAllNumeric(<<<'SQL'
            SELECT ccu.table_name, ccu.column_name, rc.delete_rule
            FROM information_schema.referential_constraints rc
            JOIN information_schema.key_column_usage kcu
                ON kcu.constraint_name = rc.constraint_name AND kcu.constraint_schema = rc.constraint_schema
            JOIN information_schema.constraint_column_usage ccu
                ON ccu.constraint_name = rc.constraint_name AND ccu.constraint_schema = rc.constraint_schema
            WHERE rc.constraint_schema = current_schema()
                AND kcu.table_name = 'group_industry_sales'
                AND kcu.column_name = 'seller_character_id'
            SQL);
    }

    private function sellerIndexesCount(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'group_industry_sales' AND indexdef LIKE '%(seller_character_id)%'",
        );
    }
}
