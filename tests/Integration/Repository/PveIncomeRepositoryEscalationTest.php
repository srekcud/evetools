<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\PveIncome;
use App\Entity\User;
use App\Enum\PveIncomeType;
use App\Repository\PveIncomeRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-533')]
final class PveIncomeRepositoryEscalationTest extends IntegrationTestCase
{
    private PveIncomeRepository $repository;
    private \DateTimeImmutable $to;
    private \DateTimeImmutable $from;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(PveIncomeRepository::class);
        $this->to = new \DateTimeImmutable('2026-11-10 12:00:00');
        $this->from = $this->to->modify('-30 days');
    }

    public function testTotalsByTypeGiveTheEscalationTotalBesideTheOtherTypesAndLeaveAnotherUsersOut(): void
    {
        $pilot = $this->createUser();
        $otherPilot = $this->createUser();
        $this->income($pilot, PveIncomeType::Escalation, 100_000_000.0, '2026-11-07 09:15:00');
        $this->income($pilot, PveIncomeType::Escalation, 80_000_000.0, '2026-11-05 18:40:00');
        $this->income($pilot, PveIncomeType::Bounty, 5_000_000.0, '2026-11-07 10:00:00');
        $this->income($pilot, PveIncomeType::LootSale, 85_000.0, '2026-11-06 20:30:00');
        $this->income($otherPilot, PveIncomeType::Escalation, 62_000_000.0, '2026-11-06 11:00:00');
        $this->flushAndClear();

        $totals = $this->repository->getTotalsByType($this->em->find(User::class, $pilot->getId()), $this->from, $this->to);

        self::assertSame(['bounty' => 5_000_000.0, 'escalation' => 180_000_000.0, 'loot_sale' => 85_000.0], $this->sortedByKey($totals));
    }

    public function testTotalsByTypeKeepAZeroPriceEscalationAsAKnownZero(): void
    {
        $pilot = $this->createUser();
        $this->income($pilot, PveIncomeType::Escalation, 0.0, '2026-11-07 09:15:00');
        $this->flushAndClear();

        $totals = $this->repository->getTotalsByType($this->em->find(User::class, $pilot->getId()), $this->from, $this->to);

        self::assertSame(['escalation' => 0.0], $totals);
    }

    public function testTotalsByTypeCoverTheWindowFromAndToIncludedAndNothingOutsideIt(): void
    {
        $pilot = $this->createUser();
        $this->income($pilot, PveIncomeType::Escalation, 1_000_000.0, '2026-10-11 12:00:00');
        $this->income($pilot, PveIncomeType::Escalation, 20_000_000.0, '2026-11-10 12:00:00');
        $this->income($pilot, PveIncomeType::Escalation, 300_000_000.0, '2026-10-11 11:59:59');
        $this->income($pilot, PveIncomeType::Escalation, 4_000_000_000.0, '2026-11-10 12:00:01');
        $this->income($pilot, PveIncomeType::Escalation, 50_000_000_000.0, '2026-10-01 08:00:00');
        $this->flushAndClear();

        $totals = $this->repository->getTotalsByType($this->em->find(User::class, $pilot->getId()), $this->from, $this->to);

        self::assertSame(['escalation' => 21_000_000.0], $totals);
    }

    public function testDailyTotalsGroupEscalationsOnTheirSaleDayAndNeverMixThemIntoLootSales(): void
    {
        $pilot = $this->createUser();
        $this->income($pilot, PveIncomeType::Escalation, 100_000_000.0, '2026-11-07 09:15:00');
        $this->income($pilot, PveIncomeType::Escalation, 7_500_000.0, '2026-11-07 21:45:00');
        $this->income($pilot, PveIncomeType::Bounty, 60_000.0, '2026-11-07 10:00:00');
        $this->income($pilot, PveIncomeType::LootSale, 40_000.0, '2026-11-07 12:00:00');
        $this->income($pilot, PveIncomeType::Escalation, 80_000_000.0, '2026-11-05 18:40:00');
        $this->flushAndClear();

        $daily = $this->repository->getDailyTotalsByType($this->em->find(User::class, $pilot->getId()), $this->from, $this->to);

        self::assertEquals(
            [
                '2026-11-05' => ['bounties' => 0.0, 'lootSales' => 0.0, 'corpProject' => 0.0, 'escalations' => 80_000_000.0],
                '2026-11-07' => ['bounties' => 60_000.0, 'lootSales' => 40_000.0, 'corpProject' => 0.0, 'escalations' => 107_500_000.0],
            ],
            $this->sortedByKey($daily),
        );
    }

    public function testDailyTotalsKeepEscalationsWhenCorpProjectIncomeIsExcludedFromLootSales(): void
    {
        $pilot = $this->createUser();
        $this->income($pilot, PveIncomeType::Escalation, 100_000_000.0, '2026-11-07 09:15:00');
        $this->income($pilot, PveIncomeType::CorpProject, 610_000.0, '2026-11-07 10:00:00');
        $this->income($pilot, PveIncomeType::LootSale, 40_000.0, '2026-11-07 12:00:00');
        $this->flushAndClear();

        $daily = $this->repository->getDailyTotalsByType($this->em->find(User::class, $pilot->getId()), $this->from, $this->to, true);

        self::assertEquals(
            ['2026-11-07' => ['bounties' => 0.0, 'lootSales' => 40_000.0, 'corpProject' => 610_000.0, 'escalations' => 100_000_000.0]],
            $daily,
        );
    }

    public function testDailyTotalsCoverTheWindowBoundsAndLeaveAnEscalationSoldFortyDaysAgoOut(): void
    {
        $pilot = $this->createUser();
        $this->income($pilot, PveIncomeType::Escalation, 1_000_000.0, '2026-10-11 12:00:00');
        $this->income($pilot, PveIncomeType::Escalation, 20_000_000.0, '2026-11-10 12:00:00');
        $this->income($pilot, PveIncomeType::Escalation, 300_000_000.0, '2026-10-01 12:00:00');
        $this->flushAndClear();

        $daily = $this->repository->getDailyTotalsByType($this->em->find(User::class, $pilot->getId()), $this->from, $this->to);

        $sortedDaily = $this->sortedByKey($daily);
        self::assertSame(['2026-10-11', '2026-11-10'], array_keys($sortedDaily));
        self::assertSame([1_000_000.0, 20_000_000.0], array_column($sortedDaily, 'escalations'));
    }

    public function testEscalationEntriesAreTheUsersEscalationIncomesInTheWindowMostRecentFirst(): void
    {
        $pilot = $this->createUser();
        $otherPilot = $this->createUser();
        $this->income($pilot, PveIncomeType::Escalation, 80_000_000.0, '2026-11-05 18:40:00', 'Guristas Den - Tamo');
        $this->income($pilot, PveIncomeType::Escalation, 100_000_000.0, '2026-11-07 09:15:00', 'Blood Raider Hideaway - Aldranette');
        $this->income($pilot, PveIncomeType::Escalation, 0.0, '2026-11-06 14:00:00', 'Serpentis Lair - Jita');
        $this->income($pilot, PveIncomeType::Escalation, 9_000_000.0, '2026-10-01 08:00:00', 'Angel Sanctum - Otela');
        $this->income($pilot, PveIncomeType::LootSale, 85_000.0, '2026-11-06 20:30:00', '6x Rogue Drone Infestation Data');
        $this->income($otherPilot, PveIncomeType::Escalation, 62_000_000.0, '2026-11-06 11:00:00', 'Sansha Hideaway - Ostingele');
        $this->flushAndClear();
        $pilot = $this->em->find(User::class, $pilot->getId());

        $entries = $this->repository->findEscalationsByUserAndDateRange($pilot, $this->from, $this->to);
        $total = $this->repository->getTotalEscalationsByUserAndDateRange($pilot, $this->from, $this->to);

        self::assertSame(
            [
                ['Blood Raider Hideaway - Aldranette', 100_000_000.0],
                ['Serpentis Lair - Jita', 0.0],
                ['Guristas Den - Tamo', 80_000_000.0],
            ],
            array_map(static fn (PveIncome $income): array => [$income->getDescription(), $income->getAmount()], $entries),
        );
        self::assertSame(180_000_000.0, $total);
    }

    public function testEscalationTotalIsAKnownZeroWithoutAnyEscalationIncome(): void
    {
        $pilot = $this->createUser();
        $this->income($pilot, PveIncomeType::LootSale, 85_000.0, '2026-11-06 20:30:00');
        $this->flushAndClear();
        $pilot = $this->em->find(User::class, $pilot->getId());

        self::assertSame(0.0, $this->repository->getTotalEscalationsByUserAndDateRange($pilot, $this->from, $this->to));
        self::assertSame([], $this->repository->findEscalationsByUserAndDateRange($pilot, $this->from, $this->to));
    }

    private function income(User $user, PveIncomeType $type, float $amount, string $date, string $description = 'Income'): void
    {
        $this->em->persist(
            (new PveIncome())
                ->setUser($user)
                ->setType($type)
                ->setDescription($description)
                ->setAmount($amount)
                ->setDate(new \DateTimeImmutable($date)),
        );
    }

    private function sortedByKey(array $values): array
    {
        ksort($values);

        return $values;
    }
}
