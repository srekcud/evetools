<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\ContributionType;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectSaleRepository;
use App\Service\Industry\Project\DistributionResult;
use App\Service\Industry\Project\ProjectDistributionService;
use App\Service\Industry\Project\UnclaimedStockLine;
use App\Service\Industry\Project\UnclaimedStockLines;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectDistributionService::class)]
#[Group('issue-1051')]
final class ProjectDistributionServiceUnclaimedStockTest extends TestCase
{
    private const string TRITANIUM_LINE_ID = '00000000-0000-0000-0000-000000105101';

    public function testUnclaimedStockMakesTheCostMarginSharesProfitPartsAndPayoutsUnknownEvenWhenEveryContributionHasAValue(): void
    {
        $result = $this->distributionOf([new UnclaimedStockLine(self::TRITANIUM_LINE_ID, 34, 'Tritanium', 50)], true);

        self::assertSame([5_000.0, 100.0, 150.0, 4_750.0, null, null], $this->totalsOf($result));
        self::assertSame(
            [[null, null, null]],
            array_map(static fn ($member): array => [$member->sharePercent, $member->profitPart, $member->payoutTotal], $result->members),
        );
        self::assertSame(3_000.0, $result->members[0]->totalCostsEngaged);
    }

    public function testTheDistributionListsTheLinesStillHoldingUnclaimedStock(): void
    {
        $line = new UnclaimedStockLine(self::TRITANIUM_LINE_ID, 34, 'Tritanium', 50);

        $result = $this->distributionOf([$line], true);

        self::assertSame([$line], $result->unclaimedStockLines);
    }

    public function testBreakEvenAndCostPerUnitNameTheUnclaimedStockAsTheirUnknownReason(): void
    {
        $result = $this->distributionOf([new UnclaimedStockLine(self::TRITANIUM_LINE_ID, 34, 'Tritanium', 50)], true);

        self::assertSame([null, 'unclaimed_stock', null, 'unclaimed_stock'], [$result->breakEvenPerUnit, $result->breakEvenUnknownReason, $result->costPerUnit, $result->costPerUnitUnknownReason]);
    }

    public function testAnEarlierUnknownReasonStaysThePrimaryOne(): void
    {
        $result = $this->distributionOf([new UnclaimedStockLine(self::TRITANIUM_LINE_ID, 34, 'Tritanium', 50)], false);

        self::assertSame(['jobs_not_delivered', 'jobs_not_delivered'], [$result->breakEvenUnknownReason, $result->costPerUnitUnknownReason]);
    }

    public function testWithoutUnclaimedStockTheCostIsTheSumOfTheApprovedValuesAndNothingIsListed(): void
    {
        $result = $this->distributionOf([], true);

        self::assertSame([5_000.0, 100.0, 150.0, 4_750.0, 3_000.0], array_slice($this->totalsOf($result), 0, 5));
        self::assertEqualsWithDelta(58.3333, $result->marginPercent, 0.0001);
        self::assertSame([], $result->unclaimedStockLines);
        self::assertSame([null, 30.0], [$result->breakEvenUnknownReason, $result->costPerUnit]);
        self::assertNotNull($result->members[0]->payoutTotal);
    }

    public function testADistributionBuiltWithoutTheUnclaimedStockReaderKeepsItsCost(): void
    {
        $result = $this->distributionOf(null, true);

        self::assertSame(3_000.0, $result->totalProjectCost);
        self::assertSame([], $result->unclaimedStockLines);
    }

    /**
     * @param list<UnclaimedStockLine>|null $unclaimedLines
     */
    private function distributionOf(?array $unclaimedLines, bool $finalJobDelivered): DistributionResult
    {
        $finalJob = $this->createStub(IndustryProjectBomLine::class);
        $finalJob->method('getTypeId')->willReturn(11993);
        $finalJob->method('getTypeName')->willReturn('Hammerhead II');
        $finalJob->method('getRequiredQuantity')->willReturn(100);
        $finalJob->method('isFulfilled')->willReturn($finalJobDelivered);
        $finalJob->method('isJob')->willReturn(true);
        $finalJob->method('getJobGroup')->willReturn('final');

        $project = $this->createStub(IndustryProject::class);
        $project->method('getBrokerFeePercent')->willReturn(2.0);
        $project->method('getSalesTaxPercent')->willReturn(3.0);
        $project->method('getBomItems')->willReturn(new ArrayCollection([$finalJob]));

        $character = $this->createStub(Character::class);
        $character->method('getName')->willReturn('Ursula Kane');
        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($character);
        $member = (new IndustryProjectParticipant())->setUser($user);
        (new \ReflectionProperty(IndustryProjectParticipant::class, 'id'))->setValue($member, Uuid::v4());
        $contribution = (new IndustryProjectContribution())->setMember($member)->setType(ContributionType::Material)->setQuantity(1)->setEstimatedValue(3_000.0);

        $contributions = $this->createStub(IndustryProjectContributionRepository::class);
        $contributions->method('findApprovedByProject')->willReturn([$contribution]);
        $sales = $this->createStub(IndustryProjectSaleRepository::class);
        $sales->method('findBy')->willReturn([(new IndustryProjectSale())->setTypeId(11993)->setQuantity(100)->setUnitPrice(50.0)->setTotalPrice(5_000.0)]);

        if ($unclaimedLines === null) {
            return (new ProjectDistributionService($contributions, $sales))->calculateDistribution($project);
        }

        $unclaimedStock = $this->createStub(UnclaimedStockLines::class);
        $unclaimedStock->method('of')->willReturn($unclaimedLines);

        return (new ProjectDistributionService($contributions, $sales, unclaimedStockLines: $unclaimedStock))->calculateDistribution($project);
    }

    /**
     * @return list<float|null>
     */
    private function totalsOf(DistributionResult $result): array
    {
        return [$result->totalRevenue, $result->brokerFee, $result->salesTax, $result->netRevenue, $result->totalProjectCost, $result->marginPercent];
    }
}
