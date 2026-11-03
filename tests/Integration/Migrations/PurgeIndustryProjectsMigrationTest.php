<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\GroupIndustrySale;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Enum\ProjectStatus;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use Psr\Log\NullLogger;

final class PurgeIndustryProjectsMigrationTest extends IntegrationTestCase
{
    private const string MIGRATION_CLASS = 'DoctrineMigrations\\Version20261103200000';

    private const array PURGED_TABLES = [
        'industry_projects',
        'industry_project_steps',
        'group_industry_projects',
        'group_industry_project_members',
        'group_industry_sales',
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->em->getConnection();
    }

    public function testEverySoloAndGroupIndustryProjectIsDeletedWithItsDependentRows(): void
    {
        $this->seedProjectsOfTwoUsers();
        self::assertSame([3, 4, 2, 4, 1], $this->purgedTableCounts());

        $this->migrateUp();

        self::assertSame([0, 0, 0, 0, 0], $this->purgedTableCounts());
    }

    public function testUsersAndCharactersAreKept(): void
    {
        $this->seedProjectsOfTwoUsers();
        $usersBefore = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users');
        $charactersBefore = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM characters');
        self::assertGreaterThanOrEqual(2, $usersBefore);

        $this->migrateUp();

        self::assertSame(
            [$usersBefore, $charactersBefore],
            [(int) $this->connection->fetchOne('SELECT COUNT(*) FROM users'), (int) $this->connection->fetchOne('SELECT COUNT(*) FROM characters')],
        );
    }

    public function testRunningTheMigrationWithoutAnyProjectChangesNothing(): void
    {
        $this->migrateUp();

        self::assertSame([0, 0, 0, 0, 0], $this->purgedTableCounts());
    }

    public function testTheMigrationCannotBeReverted(): void
    {
        $this->expectException(IrreversibleMigration::class);

        $this->purgeMigration()->down(new Schema());
    }

    private function seedProjectsOfTwoUsers(): void
    {
        $firstUser = $this->createUser();
        $this->createCharacter($firstUser, 'First-Main');
        $secondUser = $this->createUser();
        $this->createCharacter($secondUser, 'Second-Main');

        $this->persistSoloProject($firstUser, 'Sabre', ProjectStatus::Active, 2);
        $this->persistSoloProject($firstUser, 'Hurricane', ProjectStatus::Archived, 1);
        $this->persistSoloProject($secondUser, 'Rifter', ProjectStatus::Completed, 1);

        $this->persistGroupProject($firstUser, 'Group Sabre', GroupProjectStatus::Selling, true);
        $this->persistGroupProject($secondUser, 'Group Rifter', GroupProjectStatus::Draft, false);

        $this->flushAndClear();
    }

    private function persistSoloProject(User $user, string $name, ProjectStatus $status, int $stepsCount): void
    {
        $project = (new IndustryProject())
            ->setUser($user)
            ->setName($name)
            ->setProductTypeId(1_000 + $stepsCount)
            ->setRuns(10 + $stepsCount)
            ->setStatus($status);
        for ($depth = 0; $depth < $stepsCount; ++$depth) {
            $project->addStep(
                (new IndustryProjectStep())
                    ->setBlueprintTypeId(2_000 + $depth)
                    ->setProductTypeId(3_000 + $depth)
                    ->setQuantity(5 + $depth)
                    ->setRuns(7 + $depth)
                    ->setDepth($depth),
            );
        }
        $this->em->persist($project);
    }

    private function persistGroupProject(User $owner, string $name, GroupProjectStatus $status, bool $withSale): void
    {
        $project = (new GroupIndustryProject())->setOwner($owner)->setName($name)->setStatus($status);
        $project->addMember(
            (new GroupIndustryProjectMember())->setUser($owner)->setRole(GroupMemberRole::Owner)->setStatus(GroupMemberStatus::Accepted),
        );
        if ($withSale) {
            $project->addMember(
                (new GroupIndustryProjectMember())->setUser($this->createUser())->setRole(GroupMemberRole::Member)->setStatus(GroupMemberStatus::Pending),
            );
            $project->addMember(
                (new GroupIndustryProjectMember())->setUser($this->createUser())->setRole(GroupMemberRole::Admin)->setStatus(GroupMemberStatus::Removed),
            );
            $project->addSale(
                (new GroupIndustrySale())
                    ->setTypeId(0)
                    ->setTypeName('Sabre')
                    ->setQuantity(3)
                    ->setUnitPrice(61_250_000.0)
                    ->recalculateTotalPrice()
                    ->setSoldAt(new \DateTimeImmutable('2026-10-24T21:05:00+00:00'))
                    ->setRecordedBy($owner),
            );
        }
        $this->em->persist($project);
    }

    private function purgedTableCounts(): array
    {
        return array_map(
            fn (string $table): int => (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table)),
            self::PURGED_TABLES,
        );
    }

    private function migrateUp(): void
    {
        $migration = $this->purgeMigration();
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function purgeMigration(): AbstractMigration
    {
        require_once self::$kernel->getProjectDir().'/migrations/Version20261103200000.php';
        $migrationClass = self::MIGRATION_CLASS;
        $migration = new $migrationClass($this->connection, new NullLogger());
        \assert($migration instanceof AbstractMigration);

        return $migration;
    }
}
