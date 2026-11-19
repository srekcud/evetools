<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\PveExpense;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Enum\PveIncomeType;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1080')]
final class PveCharacterTotalsRepositoryTest extends IntegrationTestCase
{
    private const int MAIN_ID = 2112080001;
    private const int ALT_ID = 2112080002;

    private PveIncomeRepository $incomeRepository;
    private PveExpenseRepository $expenseRepository;
    private \DateTimeImmutable $to;
    private \DateTimeImmutable $from;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incomeRepository = self::getContainer()->get(PveIncomeRepository::class);
        $this->expenseRepository = self::getContainer()->get(PveExpenseRepository::class);
        $this->to = new \DateTimeImmutable('2026-11-10 12:00:00');
        $this->from = $this->to->modify('-30 days');
    }

    public function testIncomeTotalsGroupTheRequestedTypesByTypeAndCharacterAndKeepLinesWithoutCharacter(): void
    {
        $pilot = $this->createUser();
        $otherPilot = $this->createUser();
        $this->income($pilot, PveIncomeType::LootSale, 100.0, '2026-11-07 09:00:00', self::MAIN_ID);
        $this->income($pilot, PveIncomeType::LootSale, 50.0, '2026-11-08 09:00:00', self::MAIN_ID);
        $this->income($pilot, PveIncomeType::LootSale, 30.0, '2026-11-08 09:00:00', self::ALT_ID);
        $this->income($pilot, PveIncomeType::LootSale, 7.0, '2026-11-08 09:00:00', null);
        $this->income($pilot, PveIncomeType::Escalation, 40.0, '2026-11-08 09:00:00', self::MAIN_ID);
        $this->income($pilot, PveIncomeType::Bounty, 999.0, '2026-11-08 09:00:00', self::MAIN_ID);
        $this->income($pilot, PveIncomeType::LootSale, 888.0, '2026-09-01 09:00:00', self::MAIN_ID);
        $this->income($otherPilot, PveIncomeType::LootSale, 777.0, '2026-11-08 09:00:00', self::MAIN_ID);
        $this->flushAndClear();

        $rows = $this->incomeRepository->getTotalsByTypeAndCharacter(
            $this->em->find(User::class, $pilot->getId()),
            $this->from,
            $this->to,
            [PveIncomeType::LootSale, PveIncomeType::Escalation],
        );

        usort($rows, static fn (array $left, array $right): int => [$left['type'], $left['characterId']] <=> [$right['type'], $right['characterId']]);
        self::assertSame([
            ['type' => 'escalation', 'characterId' => self::MAIN_ID, 'total' => 40.0],
            ['type' => 'loot_sale', 'characterId' => null, 'total' => 7.0],
            ['type' => 'loot_sale', 'characterId' => self::MAIN_ID, 'total' => 150.0],
            ['type' => 'loot_sale', 'characterId' => self::ALT_ID, 'total' => 30.0],
        ], $rows);
    }

    public function testExpenseTotalsGroupByCharacterKeepLinesWithoutCharacterAndLeaveAnotherUserOut(): void
    {
        $pilot = $this->createUser();
        $otherPilot = $this->createUser();
        $this->expense($pilot, 10.0, '2026-11-07', self::MAIN_ID);
        $this->expense($pilot, 5.0, '2026-11-08', self::MAIN_ID);
        $this->expense($pilot, 4.0, '2026-11-08', self::ALT_ID);
        $this->expense($pilot, 3.0, '2026-11-08', null);
        $this->expense($pilot, 99.0, '2026-09-01', self::MAIN_ID);
        $this->expense($otherPilot, 88.0, '2026-11-08', self::MAIN_ID);
        $this->flushAndClear();

        $rows = $this->expenseRepository->getTotalsByCharacter($this->em->find(User::class, $pilot->getId()), $this->from, $this->to);

        usort($rows, static fn (array $left, array $right): int => $left['characterId'] <=> $right['characterId']);
        self::assertSame([
            ['characterId' => null, 'total' => 3.0],
            ['characterId' => self::MAIN_ID, 'total' => 15.0],
            ['characterId' => self::ALT_ID, 'total' => 4.0],
        ], $rows);
    }

    private function income(User $user, PveIncomeType $type, float $amount, string $date, ?int $characterId): void
    {
        $this->em->persist(
            (new PveIncome())
                ->setUser($user)
                ->setType($type)
                ->setDescription('Income')
                ->setAmount($amount)
                ->setDate(new \DateTimeImmutable($date))
                ->setCharacterId($characterId),
        );
    }

    private function expense(User $user, float $amount, string $date, ?int $characterId): void
    {
        $this->em->persist(
            (new PveExpense())
                ->setUser($user)
                ->setType(PveExpense::TYPE_AMMO)
                ->setDescription('Ammo')
                ->setAmount($amount)
                ->setDate(new \DateTimeImmutable($date))
                ->setCharacterId($characterId),
        );
    }
}
