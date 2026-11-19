<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Pve;

use App\Entity\Character;
use App\Entity\User;
use App\Enum\PveIncomeType;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Service\Pve\PveRevenueGridBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PveRevenueGridBuilder::class)]
#[Group('issue-1080')]
final class PveRevenueGridBuilderCharacterContributorsTest extends TestCase
{
    private const int MAIN_ID = 2112080001;
    private const int ALT_ID = 2112080002;
    private const int FOREIGN_ID = 2112080099;

    public function testLootContributorsAddUpEveryLootTypeOfTheSameCharacter(): void
    {
        $grid = $this->grid(incomeRows: [
            ['type' => PveIncomeType::LootSale->value, 'characterId' => self::MAIN_ID, 'total' => 70.0],
            ['type' => PveIncomeType::LootContract->value, 'characterId' => self::MAIN_ID, 'total' => 10.0],
            ['type' => PveIncomeType::LootSale->value, 'characterId' => self::ALT_ID, 'total' => 30.0],
        ]);

        $this->assertSame([['name' => 'Main', 'value' => 80.0], ['name' => 'Alt', 'value' => 30.0]], $grid['columns']['lootSales']['contributors']);
    }

    public function testEscalationContributorsAreTheCharactersWhoSoldThem(): void
    {
        $grid = $this->grid(incomeRows: [
            ['type' => PveIncomeType::Escalation->value, 'characterId' => self::MAIN_ID, 'total' => 40.0],
            ['type' => PveIncomeType::Escalation->value, 'characterId' => self::MAIN_ID, 'total' => 60.0],
        ]);

        $this->assertSame([['name' => 'Main', 'value' => 100.0]], $grid['columns']['escalations']['contributors']);
    }

    public function testExpenseContributorsAreTheCharactersWhoPaidThem(): void
    {
        $grid = $this->grid(expenseRows: [
            ['characterId' => self::MAIN_ID, 'total' => 10.0],
            ['characterId' => self::ALT_ID, 'total' => 5.0],
        ]);

        $this->assertSame([['name' => 'Main', 'value' => 10.0], ['name' => 'Alt', 'value' => 5.0]], $grid['columns']['expenses']['contributors']);
    }

    public function testAColumnWithALineWithoutCharacterHasUnknownContributorsInsteadOfPartialOnes(): void
    {
        $grid = $this->grid(
            incomeRows: [
                ['type' => PveIncomeType::LootSale->value, 'characterId' => self::MAIN_ID, 'total' => 70.0],
                ['type' => PveIncomeType::LootSale->value, 'characterId' => null, 'total' => 30.0],
                ['type' => PveIncomeType::Escalation->value, 'characterId' => self::ALT_ID, 'total' => 5.0],
            ],
            expenseRows: [
                ['characterId' => null, 'total' => 8.0],
            ],
        );

        $this->assertNull($grid['columns']['lootSales']['contributors']);
        $this->assertSame([['name' => 'Alt', 'value' => 5.0]], $grid['columns']['escalations']['contributors']);
        $this->assertNull($grid['columns']['expenses']['contributors']);
    }

    public function testACharacterOfNoneOfTheUsersCharactersMakesTheColumnUnknown(): void
    {
        $grid = $this->grid(incomeRows: [
            ['type' => PveIncomeType::LootSale->value, 'characterId' => self::MAIN_ID, 'total' => 70.0],
            ['type' => PveIncomeType::LootSale->value, 'characterId' => self::FOREIGN_ID, 'total' => 30.0],
        ]);

        $this->assertNull($grid['columns']['lootSales']['contributors']);
    }

    public function testAColumnWithoutAnyLineHasUnknownContributors(): void
    {
        $grid = $this->grid();

        $this->assertNull($grid['columns']['lootSales']['contributors']);
        $this->assertNull($grid['columns']['escalations']['contributors']);
        $this->assertNull($grid['columns']['expenses']['contributors']);
    }

    public function testCorpProjectLinesAreLootContributorsUnlessMiningAccountsForThem(): void
    {
        $requestedTypes = [];
        $incomeRepository = $this->incomeRepository([], $requestedTypes);

        $this->builder($incomeRepository, $this->expenseRepository([]))->build($this->user(), $this->from(), $this->to(), 7, false);
        $this->builder($incomeRepository, $this->expenseRepository([]))->build($this->user(), $this->from(), $this->to(), 7, true);

        $this->assertSame([
            [PveIncomeType::LootSale, PveIncomeType::LootContract, PveIncomeType::CorpProject, PveIncomeType::Escalation],
            [PveIncomeType::LootSale, PveIncomeType::LootContract, PveIncomeType::Escalation],
        ], $requestedTypes);
    }

    public function testBountiesStillReadTheirContributorsFromTheDescriptionAndTheNetStaysUnknown(): void
    {
        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('getTotalsByTypeAndDescription')->willReturn([
            ['type' => PveIncomeType::Bounty->value, 'description' => 'Bounty - Main', 'total' => 70.0],
        ]);
        $incomeRepository->method('getTotalsByTypeAndCharacter')->willReturn([]);

        $grid = $this->builder($incomeRepository, $this->expenseRepository([]))->build($this->user(), $this->from(), $this->to(), 7, false);

        $this->assertSame([['name' => 'Main', 'value' => 70.0]], $grid['columns']['bounties']['contributors']);
        $this->assertArrayNotHasKey('contributors', $grid['net']);
    }

    /**
     * @param list<array{type: string, characterId: int|null, total: float}> $incomeRows
     * @param list<array{characterId: int|null, total: float}> $expenseRows
     *
     * @return array<string, mixed>
     */
    private function grid(array $incomeRows = [], array $expenseRows = []): array
    {
        $requestedTypes = [];

        return $this->builder($this->incomeRepository($incomeRows, $requestedTypes), $this->expenseRepository($expenseRows))
            ->build($this->user(), $this->from(), $this->to(), 7, false);
    }

    /**
     * @param list<array{type: string, characterId: int|null, total: float}> $rows
     * @param list<list<PveIncomeType>> $requestedTypes
     */
    private function incomeRepository(array $rows, array &$requestedTypes): PveIncomeRepository
    {
        $repository = $this->createStub(PveIncomeRepository::class);
        $repository->method('getTotalsByTypeAndCharacter')->willReturnCallback(
            static function (User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, array $types) use ($rows, &$requestedTypes): array {
                $requestedTypes[] = $types;

                return $rows;
            },
        );

        return $repository;
    }

    /**
     * @param list<array{characterId: int|null, total: float}> $rows
     */
    private function expenseRepository(array $rows): PveExpenseRepository
    {
        $repository = $this->createStub(PveExpenseRepository::class);
        $repository->method('getTotalsByCharacter')->willReturn($rows);

        return $repository;
    }

    private function builder(PveIncomeRepository $incomeRepository, PveExpenseRepository $expenseRepository): PveRevenueGridBuilder
    {
        return new PveRevenueGridBuilder($incomeRepository, $expenseRepository);
    }

    private function user(): User
    {
        $user = new User();
        $user->addCharacter((new Character())->setEveCharacterId(self::MAIN_ID)->setName('Main'));
        $user->addCharacter((new Character())->setEveCharacterId(self::ALT_ID)->setName('Alt'));

        return $user;
    }

    private function from(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-10 12:00:00');
    }

    private function to(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-17 12:00:00');
    }
}
