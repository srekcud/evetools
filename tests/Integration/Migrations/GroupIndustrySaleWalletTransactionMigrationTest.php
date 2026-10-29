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
final class GroupIndustrySaleWalletTransactionMigrationTest extends IntegrationTestCase
{
    private const string LINK_COLUMN = 'wallet_transaction_id';

    private Connection $connection;
    private string $saleId;
    private string $sellerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->em->getConnection();

        $owner = $this->createUser();
        $oMain = $this->createCharacter($owner, 'O-Main')->setEveCharacterId(2120000001);
        $owner->setMainCharacter($oMain);
        $project = (new GroupIndustryProject())->setOwner($owner)->setName('Sabre')->setStatus(GroupProjectStatus::Selling);
        $this->em->persist($project);
        $sale = (new GroupIndustrySale())
            ->setTypeId(0)
            ->setTypeName('Sabre')
            ->setQuantity(3)
            ->setUnitPrice(61_250_000.0)
            ->recalculateTotalPrice()
            ->setSoldAt(new \DateTimeImmutable('2026-10-24T21:05:00+00:00'))
            ->setRecordedBy($owner)
            ->setSeller($oMain);
        $project->addSale($sale);
        $this->flushAndClear();
        $this->saleId = (string) $sale->getId();
        $this->sellerId = (string) $oMain->getId();
    }

    public function testSaleRecordedBeforeTheMigrationIsNotLinkedAndKeepsItsSellerAndAmounts(): void
    {
        $this->migrateDown();

        $this->migrateUp();

        self::assertSame([null, $this->sellerId, 3, 183_750_000.0], $this->saleRow());
    }

    public function testMigrationAddsANullableUniqueLinkToTheCachedWalletTransactionsClearedOnTheirDeletion(): void
    {
        $this->migrateDown();

        $this->migrateUp();

        self::assertSame(
            [['YES'], [['cached_wallet_transactions', 'id', 'SET NULL']], 1],
            [$this->linkColumnNullability(), $this->linkForeignKeys(), $this->uniqueLinkIndexesCount()],
        );
    }

    public function testMigrationDownRemovesTheLinkItsForeignKeyAndItsUniqueIndexAndKeepsTheSale(): void
    {
        $this->migrateDown();

        self::assertSame(
            [[], [], 0, 1],
            [
                $this->linkColumnNullability(),
                $this->linkForeignKeys(),
                $this->uniqueLinkIndexesCount(),
                (int) $this->connection->fetchOne('SELECT COUNT(*) FROM group_industry_sales WHERE id = ?', [$this->saleId]),
            ],
        );
    }

    private function migrateUp(): void
    {
        $migration = $this->linkMigration();
        $migration->up(new Schema());
        $this->execute($migration);
    }

    private function migrateDown(): void
    {
        $migration = $this->linkMigration();
        $migration->down(new Schema());
        $this->execute($migration);
    }

    private function execute(AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function linkMigration(): AbstractMigration
    {
        $migrationFiles = array_values(array_filter(
            glob(self::$kernel->getProjectDir() . '/migrations/Version*.php') ?: [],
            static fn (string $migrationFile): bool => str_contains((string) file_get_contents($migrationFile), 'group_industry_sales')
                && str_contains((string) file_get_contents($migrationFile), self::LINK_COLUMN),
        ));
        self::assertCount(1, $migrationFiles, 'exactly one migration adds group_industry_sales.wallet_transaction_id');

        require_once $migrationFiles[0];
        $migrationClass = 'DoctrineMigrations\\' . basename($migrationFiles[0], '.php');
        $migration = new $migrationClass($this->connection, new NullLogger());
        \assert($migration instanceof AbstractMigration);

        return $migration;
    }

    private function saleRow(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT wallet_transaction_id, seller_character_id, quantity, total_price FROM group_industry_sales WHERE id = ?',
            [$this->saleId],
        );
        \assert($row !== false);

        return [$row['wallet_transaction_id'], $row['seller_character_id'], (int) $row['quantity'], (float) $row['total_price']];
    }

    private function linkColumnNullability(): array
    {
        return $this->connection->fetchFirstColumn(
            "SELECT is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'group_industry_sales' AND column_name = ?",
            [self::LINK_COLUMN],
        );
    }

    private function linkForeignKeys(): array
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
                AND kcu.column_name = 'wallet_transaction_id'
            SQL);
    }

    private function uniqueLinkIndexesCount(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'group_industry_sales' AND indexdef LIKE 'CREATE UNIQUE INDEX %(wallet_transaction_id)%'",
        );
    }
}
