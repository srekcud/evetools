<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\PveIncome;
use App\Entity\User;
use App\Enum\PveIncomeType;
use App\Repository\PveIncomeRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1065')]
final class PveIncomeRepositoryRevenueGridTest extends IntegrationTestCase
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

    public function testDailyTotalsPerIncomeTypeSumTheSameTypeOfTheSameDayAndKeepTypesApart(): void
    {
        $pilot = $this->createUser();
        $otherPilot = $this->createUser();
        $this->income($pilot, PveIncomeType::Bounty, 100.0, '2026-11-07 09:15:00');
        $this->income($pilot, PveIncomeType::Bounty, 50.0, '2026-11-07 18:40:00');
        $this->income($pilot, PveIncomeType::Ess, 7.0, '2026-11-07 18:41:00');
        $this->income($pilot, PveIncomeType::Bounty, 20.0, '2026-11-08 10:00:00');
        $this->income($pilot, PveIncomeType::Bounty, 9.0, '2026-09-01 10:00:00');
        $this->income($otherPilot, PveIncomeType::Bounty, 999.0, '2026-11-07 10:00:00');
        $this->flushAndClear();

        $daily = $this->repository->getDailyTotalsPerIncomeType($this->em->find(User::class, $pilot->getId()), $this->from, $this->to);

        ksort($daily);
        self::assertSame(['2026-11-07' => ['bounty' => 150.0, 'ess' => 7.0], '2026-11-08' => ['bounty' => 20.0]], $this->sortedTypes($daily));
    }

    public function testTotalsByTypeAndDescriptionGroupTheRequestedTypesOnlyAndLeaveAnotherUserOut(): void
    {
        $pilot = $this->createUser();
        $otherPilot = $this->createUser();
        $this->income($pilot, PveIncomeType::Bounty, 100.0, '2026-11-07 09:00:00', 'Bounty - Main');
        $this->income($pilot, PveIncomeType::Bounty, 50.0, '2026-11-08 09:00:00', 'Bounty - Main');
        $this->income($pilot, PveIncomeType::Bounty, 30.0, '2026-11-08 09:00:00', 'Bounty - Alt');
        $this->income($pilot, PveIncomeType::Ess, 7.0, '2026-11-08 09:00:00', 'ESS - Main');
        $this->income($pilot, PveIncomeType::LootSale, 999.0, '2026-11-08 09:00:00', '12x Tritanium');
        $this->income($otherPilot, PveIncomeType::Bounty, 888.0, '2026-11-08 09:00:00', 'Bounty - Main');
        $this->flushAndClear();

        $rows = $this->repository->getTotalsByTypeAndDescription(
            $this->em->find(User::class, $pilot->getId()),
            $this->from,
            $this->to,
            [PveIncomeType::Bounty, PveIncomeType::Ess],
        );

        usort($rows, static fn (array $a, array $b): int => [$a['type'], $a['description']] <=> [$b['type'], $b['description']]);
        self::assertSame([
            ['type' => 'bounty', 'description' => 'Bounty - Alt', 'total' => 30.0],
            ['type' => 'bounty', 'description' => 'Bounty - Main', 'total' => 150.0],
            ['type' => 'ess', 'description' => 'ESS - Main', 'total' => 7.0],
        ], $rows);
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

    /**
     * @param array<string, array<string, float>> $daily
     * @return array<string, array<string, float>>
     */
    private function sortedTypes(array $daily): array
    {
        foreach ($daily as $day => $types) {
            ksort($types);
            $daily[$day] = $types;
        }

        return $daily;
    }
}
