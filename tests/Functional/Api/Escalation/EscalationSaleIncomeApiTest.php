<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Escalation;

use App\Entity\Escalation;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Enum\EscalationSaleStatus;
use App\Enum\EscalationVisibility;
use App\Enum\PveIncomeType;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-533')]
final class EscalationSaleIncomeApiTest extends EscalationIncomeApiTestCase
{
    public function testSellingAnEscalationCreatesTheOwnersIncomeAtItsPriceOnTheSaleDay(): void
    {
        $escalation = $this->escalationOf($this->pilot, EscalationSaleStatus::EnVente, 100_000_000, EscalationVisibility::Corp);
        $escalationId = $escalation->getId()?->toRfc4122();

        $this->patchEscalation($this->pilot, $escalationId, ['saleStatus' => 'vendu']);

        self::assertResponseStatusCodeSame(200);
        $incomes = $this->escalationIncomesOf($this->pilot);
        self::assertCount(1, $incomes);
        self::assertSame(
            [100_000_000.0, 'Blood Raider Hideaway - Aldranette', (new \DateTimeImmutable())->format('Y-m-d'), $escalationId],
            [$incomes[0]->getAmount(), $incomes[0]->getDescription(), $incomes[0]->getDate()->format('Y-m-d'), $incomes[0]->getEscalation()?->getId()?->toRfc4122()],
        );
    }

    public function testPuttingASoldEscalationBackOnSaleRemovesItsIncome(): void
    {
        $income = $this->soldEscalationIncome($this->pilot, 100_000_000.0, $this->saleDate);
        $escalationId = $income->getEscalation()?->getId()?->toRfc4122();

        $this->patchEscalation($this->pilot, $escalationId, ['saleStatus' => 'envente']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->escalationIncomesOf($this->pilot));
    }

    public function testChangingThePriceOfASoldEscalationFollowsInItsIncomeWithTheSameDate(): void
    {
        $income = $this->soldEscalationIncome($this->pilot, 100_000_000.0, $this->saleDate);
        $escalationId = $income->getEscalation()?->getId()?->toRfc4122();

        $this->patchEscalation($this->pilot, $escalationId, ['price' => 80_000_000]);

        self::assertResponseStatusCodeSame(200);
        $incomes = $this->escalationIncomesOf($this->pilot);
        self::assertCount(1, $incomes);
        self::assertSame(
            [80_000_000.0, $this->saleDate->format('Y-m-d H:i:s')],
            [$incomes[0]->getAmount(), $incomes[0]->getDate()->format('Y-m-d H:i:s')],
        );
    }

    public function testSavingTheNotesOfASoldEscalationKeepsASingleIncomeOnTheSameDate(): void
    {
        $income = $this->soldEscalationIncome($this->pilot, 100_000_000.0, $this->saleDate);
        $escalationId = $income->getEscalation()?->getId()?->toRfc4122();

        $this->patchEscalation($this->pilot, $escalationId, ['saleStatus' => 'vendu', 'notes' => 'Paid by contract']);

        self::assertResponseStatusCodeSame(200);
        $incomes = $this->escalationIncomesOf($this->pilot);
        self::assertCount(1, $incomes);
        self::assertSame(
            [100_000_000.0, $this->saleDate->format('Y-m-d H:i:s')],
            [$incomes[0]->getAmount(), $incomes[0]->getDate()->format('Y-m-d H:i:s')],
        );
    }

    public function testAnotherMemberCannotSellAVisibleEscalationAndItCreatesNoIncome(): void
    {
        $escalation = $this->escalationOf($this->pilot, EscalationSaleStatus::EnVente, 100_000_000, EscalationVisibility::Public);
        $escalationId = $escalation->getId()?->toRfc4122();

        $this->patchEscalation($this->otherMember, $escalationId, ['saleStatus' => 'vendu']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->escalationIncomesOf($this->pilot));
        self::assertSame([], $this->escalationIncomesOf($this->otherMember));
        $this->em->clear();
        $stored = $this->em->find(Escalation::class, $escalationId);
        self::assertSame(EscalationSaleStatus::EnVente, $stored?->getSaleStatus());
    }

    #[Group('issue-533')]
    public function testPriceBeyondInteger4CurrentlyAnswers500AndCreatesNoIncomeIssue533(): void
    {
        $escalation = $this->escalationOf($this->pilot, EscalationSaleStatus::EnVente, 100_000_000, EscalationVisibility::Corp);

        $this->patchEscalation($this->pilot, $escalation->getId()?->toRfc4122(), ['saleStatus' => 'vendu', 'price' => 2_147_483_648]);

        self::assertResponseStatusCodeSame(500);
        self::assertSame([], $this->escalationIncomesOf($this->pilot));
    }

    public function testStatsCountASoldEscalationInTheIncomeOfThePeriod(): void
    {
        $this->soldEscalationIncome($this->pilot, 100_000_000.0, $this->saleDate);
        $this->incomeOf($this->pilot, PveIncomeType::Bounty, 5_000_000.0, $this->saleDate, 'Bounty - Ilse Varn');

        $stats = $this->getJson($this->pilot, '/api/pve/stats?days=30');

        self::assertSame(100_000_000.0, $stats['totals']['escalations']);
        self::assertSame(5_000_000.0, $stats['totals']['bounties']);
        self::assertSame(105_000_000.0, $stats['totals']['income']);
    }

    public function testStatsLeaveOutAnEscalationSoldFortyDaysAgoOnAThirtyDayPeriod(): void
    {
        $this->soldEscalationIncome($this->pilot, 100_000_000.0, new \DateTimeImmutable('-40 days'));

        $stats = $this->getJson($this->pilot, '/api/pve/stats?days=30');

        self::assertSame(0.0, $stats['totals']['escalations']);
        self::assertSame(0.0, $stats['totals']['income']);
    }

    public function testStatsOfAMemberDoNotCountTheEscalationSoldByAnotherMember(): void
    {
        $this->soldEscalationIncome($this->pilot, 100_000_000.0, $this->saleDate);

        $stats = $this->getJson($this->otherMember, '/api/pve/stats?days=30');

        self::assertSame(0.0, $stats['totals']['escalations']);
        self::assertSame(0.0, $stats['totals']['income']);
    }

    public function testDashboardCountsASoldEscalationInThePveTotalAndItsBreakdown(): void
    {
        $this->soldEscalationIncome($this->pilot, 100_000_000.0, $this->saleDate);

        $dashboard = $this->getJson($this->pilot, '/api/ledger/dashboard?days=30');

        self::assertSame(100_000_000.0, $dashboard['totals']['pve']);
        self::assertSame(100_000_000.0, $dashboard['pveBreakdown']['escalations']);
    }

    public function testDashboardLeavesOutAnEscalationSoldFortyDaysAgoOnAThirtyDayPeriod(): void
    {
        $this->soldEscalationIncome($this->pilot, 100_000_000.0, new \DateTimeImmutable('-40 days'));

        $dashboard = $this->getJson($this->pilot, '/api/ledger/dashboard?days=30');

        self::assertSame(0.0, $dashboard['totals']['pve']);
        self::assertSame(0.0, $dashboard['pveBreakdown']['escalations']);
    }

    public function testDashboardKeepsTheEscalationIncomeWhenCorpProjectsAreAccountedAsMining(): void
    {
        $this->soldEscalationIncome($this->pilot, 100_000_000.0, $this->saleDate);
        $this->em->persist((new UserLedgerSettings())->setUser($this->pilot)->setCorpProjectAccounting(UserLedgerSettings::CORP_PROJECT_ACCOUNTING_MINING));
        $this->em->flush();

        $dashboard = $this->getJson($this->pilot, '/api/ledger/dashboard?days=30');

        self::assertSame('mining', $dashboard['settings']['corpProjectAccounting']);
        self::assertSame(100_000_000.0, $dashboard['totals']['pve']);
        self::assertSame(100_000_000.0, $dashboard['pveBreakdown']['escalations']);
    }

    public function testPveDailyStatsPutASoldEscalationOnItsSaleDay(): void
    {
        $this->soldEscalationIncome($this->pilot, 100_000_000.0, $this->saleDate);

        $stats = $this->getJson($this->pilot, '/api/pve/stats/daily?days=30');

        $days = array_column($stats['daily'], null, 'date');
        self::assertSame(100_000_000.0, $days[$this->saleDate->format('Y-m-d')]['escalations']);
        self::assertSame(100_000_000.0, $days[$this->saleDate->format('Y-m-d')]['income']);
        self::assertSame(0.0, $days[$this->saleDate->modify('+1 day')->format('Y-m-d')]['escalations']);
    }

    public function testLedgerDailyStatsPutASoldEscalationInThePveOfItsSaleDay(): void
    {
        $this->soldEscalationIncome($this->pilot, 100_000_000.0, $this->saleDate);

        $stats = $this->getJson($this->pilot, '/api/ledger/stats/daily?days=30');

        $days = array_column($stats['daily'], null, 'date');
        self::assertSame(100_000_000.0, $days[$this->saleDate->format('Y-m-d')]['pve']);
        self::assertSame(0.0, $days[$this->saleDate->modify('+1 day')->format('Y-m-d')]['pve']);
    }

    public function testIncomeListsASoldEscalationLabelledEscalationAndCountsItInTheProfit(): void
    {
        $this->soldEscalationIncome($this->pilot, 100_000_000.0, $this->saleDate);

        $income = $this->getJson($this->pilot, '/api/pve/income?days=30');

        self::assertSame(100_000_000.0, $income['escalations']['total']);
        self::assertSame(1, $income['escalations']['count']);
        self::assertSame(
            ['escalation', 'Escalation', 100_000_000.0, 'Blood Raider Hideaway - Aldranette'],
            [
                $income['escalations']['entries'][0]['refType'],
                $income['escalations']['entries'][0]['refTypeLabel'],
                $income['escalations']['entries'][0]['amount'],
                $income['escalations']['entries'][0]['description'],
            ],
        );
        self::assertSame(100_000_000.0, $income['profit']);
    }

    private function patchEscalation(User $caller, ?string $escalationId, array $payload): void
    {
        $this->client->loginUser($caller, 'api');
        $this->client->request(
            'PATCH',
            '/api/escalations/'.$escalationId,
            server: ['CONTENT_TYPE' => 'application/merge-patch+json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
