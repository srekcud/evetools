<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\SaleChannel;
use App\Repository\CachedWalletJournalEntryRepository;
use App\Repository\IndustryProjectSaleRepository;
use App\Repository\IndustryProjectTransferReceiptRepository;
use App\Service\Industry\Project\ContractSaleFees;
use App\Service\Industry\Project\DistributionResult;
use App\Service\Industry\Project\ProjectDistributionService;
use App\Service\Industry\Project\ProjectSettlementCalculator;
use App\Service\Industry\Project\TransferJournalVerifier;
use App\Service\Industry\Project\TransferPlanner;
use App\Service\Industry\Project\TransfersUnavailableReason;
use App\Service\Industry\Project\UnclaimedStockLine;
use App\Service\Industry\Project\UnsoldProductsOfProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectSettlementCalculator::class)]
#[Group('issue-1051')]
final class ProjectSettlementCalculatorUnclaimedStockTest extends TestCase
{
    public function testTransfersAreUnavailableWhileAnyLineStillHoldsUnclaimedStock(): void
    {
        $settlement = $this->settlementOf([new UnclaimedStockLine('00000000-0000-0000-0000-000000105101', 34, 'Tritanium', 50)], 25.0);

        self::assertSame(TransfersUnavailableReason::UnclaimedStock, $settlement->transfersUnavailableReason);
        self::assertNull($settlement->transfers);
    }

    public function testUnclaimedStockOutranksAnUnknownCost(): void
    {
        $settlement = $this->settlementOf([new UnclaimedStockLine('00000000-0000-0000-0000-000000105101', 34, 'Tritanium', 50)], null);

        self::assertSame(TransfersUnavailableReason::UnclaimedStock, $settlement->transfersUnavailableReason);
    }

    public function testWithoutUnclaimedStockAnUnknownCostKeepsItsOwnReason(): void
    {
        $settlement = $this->settlementOf([], null);

        self::assertSame(TransfersUnavailableReason::UnknownCost, $settlement->transfersUnavailableReason);
    }

    /**
     * @param list<UnclaimedStockLine> $unclaimedLines
     */
    private function settlementOf(array $unclaimedLines, ?float $marginPercent): \App\Service\Industry\Project\ProjectSettlement
    {
        $project = (new IndustryProject())->setOwner(new User())->setBrokerFeePercent(2.0)->setSalesTaxPercent(3.0);
        $sales = $this->createStub(IndustryProjectSaleRepository::class);
        $sales->method('findBy')->willReturn([(new IndustryProjectSale())->setTotalPrice(5_000.0)->setChannel(SaleChannel::Market)]);

        $distribution = $this->createStub(ProjectDistributionService::class);
        $distribution->method('calculateDistribution')->willReturn(new DistributionResult(
            totalRevenue: 5_000.0,
            brokerFee: 100.0,
            salesTax: 150.0,
            netRevenue: 4_750.0,
            totalProjectCost: $marginPercent === null ? null : 3_000.0,
            marginPercent: $marginPercent,
            members: [],
            unclaimedStockLines: $unclaimedLines,
        ));

        $journal = $this->createStub(CachedWalletJournalEntryRepository::class);

        return (new ProjectSettlementCalculator(
            $distribution,
            $sales,
            $this->createStub(IndustryProjectTransferReceiptRepository::class),
            new TransferPlanner(),
            new UnsoldProductsOfProject(),
            new ContractSaleFees($journal),
            new TransferJournalVerifier($journal),
        ))->calculate($project);
    }
}
