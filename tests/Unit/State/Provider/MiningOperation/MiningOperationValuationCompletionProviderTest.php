<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Get;
use App\ApiResource\MiningOperation\MiningOperationValuationResource;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningOperationVisibility;
use App\Enum\MiningSplitMode;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationValuator;
use App\Service\MiningOperation\MiningShareFreezer;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use App\State\Provider\MiningOperation\MiningOperationValuationProvider;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use App\Tests\Unit\Service\MiningOperation\BuildsLedgerSnapshots;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use App\Tests\Unit\Service\MiningOperation\BuildsValuedSpodumainOperation;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[CoversClass(MiningOperationValuationProvider::class)]
#[CoversClass(MiningOperationResourceMapper::class)]
#[Group('issue-265')]
final class MiningOperationValuationCompletionProviderTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;

    private const string READ_AT = '2026-11-05T21:58:00+00:00';

    private MockClock $clock;
    private MiningOperationValuator $valuator;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->clock = new MockClock(self::READ_AT);
        $this->valuator = $this->valuatorOn($this->jitaMarketSyncedAt($this->jitaSellPrices()), $this->clock);
    }

    public function testOwnerMayCompleteAClosingEachForThemselvesOperationWhoseQuantitiesAndPricesAreKnown(): void
    {
        self::assertSame(
            ['canComplete' => true, 'completionBlockers' => [], 'canFreeze' => false, 'freezeBlockers' => ['each_for_themselves']],
            $this->completionPartOf($this->valuationSeenBy($this->aria, $this->eachForThemselvesE1Operation())),
        );
    }

    public function testPlainMemberReadsTheSameCompletionBlockersWithoutTheRightToComplete(): void
    {
        self::assertSame(
            ['canComplete' => false, 'completionBlockers' => [], 'canFreeze' => false, 'freezeBlockers' => ['each_for_themselves']],
            $this->completionPartOf($this->valuationSeenBy($this->brann, $this->eachForThemselvesE1Operation())),
        );
    }

    public function testUnknownQuantityOfBrannBlocksTheCompletionWhileTheFreezeStaysRefusedToEachForThemselves(): void
    {
        self::assertSame(
            ['canComplete' => false, 'completionBlockers' => ['quantities_not_known'], 'canFreeze' => false, 'freezeBlockers' => ['each_for_themselves']],
            $this->completionPartOf($this->valuationSeenBy($this->aria, $this->eachForThemselvesE1Operation(['Brann II']))),
        );
    }

    public function testValueProrataOperationHasNoCompletionBlockersAndCannotBeCompleted(): void
    {
        $valuation = $this->valuationSeenBy($this->aria, $this->withCyraBoostingForTenPercent($this->e1Operation()));

        self::assertSame(
            ['canComplete' => false, 'completionBlockers' => null, 'canFreeze' => true, 'freezeBlockers' => []],
            $this->completionPartOf($valuation),
        );
    }

    public function testCompletedOperationIsServedFrozenWithNothingLeftToComplete(): void
    {
        $operation = $this->eachForThemselvesE1Operation();
        $this->freezer()->complete($operation, $this->aria);

        $valuation = $this->valuationSeenBy($this->aria, $operation);

        self::assertSame(
            ['status' => 'completed', 'frozen' => true, 'totalValue' => self::E1_TOTAL_VALUE, 'boosterCut' => null, 'canComplete' => false, 'completionBlockers' => []],
            [
                'status' => $valuation->status,
                'frozen' => $valuation->frozen,
                'totalValue' => $valuation->totalValue,
                'boosterCut' => $valuation->boosterCut,
                'canComplete' => $valuation->canComplete,
                'completionBlockers' => $valuation->completionBlockers,
            ],
        );
    }

    public function testCorporationMemberReadsTheResultOfAnArchivedOperationOpenToTheCorporation(): void
    {
        $operation = $this->eachForThemselvesE1Operation();
        $operation->setStatus(MiningOperationStatus::Completed);

        try {
            $totalValueSeenByElia = $this->valuationSeenBy($this->elia, $operation)->totalValue;
        } catch (AccessDeniedHttpException $refusal) {
            $totalValueSeenByElia = $refusal->getMessage();
        }

        self::assertSame(self::E1_TOTAL_VALUE, $totalValueSeenByElia);
    }

    public function testCorporationMemberCannotReadTheValuationOfAnOperationThatIsNotArchived(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this operation');

        $this->valuationSeenBy($this->elia, $this->eachForThemselvesE1Operation());
    }

    public function testCorporationMemberCannotReadTheResultOfAnArchivedPrivateOperation(): void
    {
        $operation = $this->eachForThemselvesE1Operation();
        $operation->setStatus(MiningOperationStatus::Completed)->setVisibility(MiningOperationVisibility::Private);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this operation');

        $this->valuationSeenBy($this->elia, $operation);
    }

    private function eachForThemselvesE1Operation(array $unreadStarts = []): MiningOperation
    {
        $operation = $this->e1Operation(unreadStarts: $unreadStarts, splitMode: MiningSplitMode::EachForThemselves);
        $operation->updateValuationSettings(valuationSide: MarketSide::Sell);

        return $operation;
    }

    private function freezer(): MiningShareFreezer
    {
        return new MiningShareFreezer($this->valuator, $this->typeNameResolverOfSdeOres(), $this->createStub(EntityManagerInterface::class), $this->clock);
    }

    private function valuationSeenBy(User $user, MiningOperation $operation): MiningOperationValuationResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $provider = new MiningOperationValuationProvider(
            $security,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationAccess(),
            $this->valuator,
            $this->typeNameResolverOfSdeOres(),
            new MiningOperationResourceMapper(),
        );

        return $provider->provide(new Get(), ['operationId' => $operation->getId()?->toRfc4122()]);
    }

    private function completionPartOf(MiningOperationValuationResource $valuation): array
    {
        return [
            'canComplete' => $valuation->canComplete,
            'completionBlockers' => $valuation->completionBlockers,
            'canFreeze' => $valuation->canFreeze,
            'freezeBlockers' => $valuation->freezeBlockers,
        ];
    }
}
