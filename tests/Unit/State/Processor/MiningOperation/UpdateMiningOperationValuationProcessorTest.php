<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Input\MiningOperation\UpdateMiningOperationValuationInput;
use App\ApiResource\MiningOperation\MiningOperationValuationResource;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningOperationVisibility;
use App\Enum\MiningSplitMode;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAccess;
use App\State\Processor\MiningOperation\UpdateMiningOperationValuationProcessor;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use App\Tests\Unit\Service\MiningOperation\BuildsLedgerSnapshots;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use App\Tests\Unit\Service\MiningOperation\BuildsValuedSpodumainOperation;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UpdateMiningOperationValuationProcessor::class)]
#[Group('issue-264')]
#[AllowMockObjectsWithoutExpectations]
final class UpdateMiningOperationValuationProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;

    private const int REFINERY = 1_000_000_000_001;
    private const string REFINERY_NAME = 'Refinery B-1';
    private const string READ_AT = '2026-11-05T21:50:00+00:00';

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private UpdateMiningOperationValuationProcessor $processor;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
        $this->processor = new UpdateMiningOperationValuationProcessor(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            $this->memberRepositoryReadingOperations(),
            new MiningOperationAccess(),
            $this->valuatorOn($this->jitaMarketSyncedAt($this->jitaSellPrices()), new MockClock(self::READ_AT)),
            $this->typeNameResolverOfSdeOres(),
            new MiningOperationResourceMapper(),
            $this->entityManager,
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    public function testOwnerChoosesTheSellSideAndCyraAsBoosterForTenPercentAndEveryParticipantIsNotified(): void
    {
        $operation = $this->e1Operation();
        $this->actAs($this->aria);
        $publishedUpdates = $this->capturePublishedUpdates($this->hub);
        $this->entityManager->expects($this->once())->method('flush');

        $valuation = $this->patch($operation, ['valuationSide' => 'sell', 'boosterCutPercent' => 10, 'boosterMemberId' => $this->memberIdOf($operation, $this->cyra)]);

        self::assertSame(
            [
                'settings' => $this->settings(['valuationSide' => 'sell', 'boosterCutPercent' => 10, 'boosterMemberId' => $this->memberIdOf($operation, $this->cyra), 'boosterCharacterName' => 'Cyra Lune']),
                'totals' => [self::E1_TOTAL_VALUE, 556_773],
            ],
            ['settings' => $valuation->settings, 'totals' => [$valuation->totalValue, $valuation->boosterCut]],
        );
        self::assertSame(
            $this->expectedMessages([$this->aria, $this->brann, $this->cyra], $operation, 'valuation_updated', []),
            $this->publishedMessages($publishedUpdates),
        );
    }

    public function testAbsentFieldsKeepTheirValue(): void
    {
        $operation = $this->withCyraBoostingForTenPercent($this->e1Operation());
        $this->actAs($this->aria);

        $valuation = $this->patch($operation, ['corporationCutPercent' => 5]);

        self::assertSame(
            $this->settings(['valuationSide' => 'sell', 'boosterCutPercent' => 10, 'corporationCutPercent' => 5, 'boosterMemberId' => $this->memberIdOf($operation, $this->cyra), 'boosterCharacterName' => 'Cyra Lune']),
            $valuation->settings,
        );
    }

    public function testBoosterSetBackToNullLeavesTheOperationWithoutBooster(): void
    {
        $operation = $this->withCyraBoostingForTenPercent($this->e1Operation());
        $this->actAs($this->aria);

        $valuation = $this->patch($operation, ['boosterMemberId' => null]);

        self::assertSame(
            $this->settings(['valuationSide' => 'sell', 'boosterCutPercent' => 10]),
            $valuation->settings,
        );
    }

    public function testStructureMarketWithoutStructureIsRefused(): void
    {
        $this->assertPatchRefused($this->e1Operation(), ['valuationMarket' => 'structure'], 'A structure market needs a structure');
    }

    public function testBackToJitaForgetsTheStructure(): void
    {
        $operation = $this->e1Operation();
        $this->actAs($this->aria);
        $onStructure = $this->patch($operation, ['valuationMarket' => 'structure', 'structureId' => self::REFINERY, 'structureName' => self::REFINERY_NAME]);

        $backOnJita = $this->patch($operation, ['valuationMarket' => 'jita']);

        self::assertSame(
            [
                $this->settings(['valuationMarket' => 'structure', 'structureId' => self::REFINERY, 'structureName' => self::REFINERY_NAME]),
                $this->settings(),
            ],
            [$onStructure->settings, $backOnJita->settings],
        );
    }

    public function testReprocessedValuationWithoutYieldIsRefused(): void
    {
        $this->assertPatchRefused($this->e1Operation(), ['valuationForm' => 'reprocessed'], 'A reprocessed valuation needs a reprocessing yield');
    }

    public function testLeavingTheReprocessedFormForgetsTheYield(): void
    {
        $operation = $this->e1Operation();
        $this->actAs($this->aria);
        $reprocessed = $this->patch($operation, ['valuationForm' => 'reprocessed', 'reprocessYield' => 0.72358]);

        $compressed = $this->patch($operation, ['valuationForm' => 'compressed']);

        self::assertSame(
            [$this->settings(['valuationForm' => 'reprocessed', 'reprocessYield' => 0.72358]), $this->settings()],
            [$reprocessed->settings, $compressed->settings],
        );
    }

    public function testBoosterAndCorporationCutsAboveOneHundredPercentAreRefused(): void
    {
        $this->assertPatchRefused($this->e1Operation(), ['boosterCutPercent' => 60, 'corporationCutPercent' => 41], 'Booster and corporation cuts cannot exceed 100 %');
    }

    public function testStructureIsCheckedBeforeTheCuts(): void
    {
        $this->assertPatchRefused($this->e1Operation(), ['valuationMarket' => 'structure', 'boosterCutPercent' => 60, 'corporationCutPercent' => 41], 'A structure market needs a structure');
    }

    public function testCorporationCutOfAnOperationWithoutCorporationIsRefused(): void
    {
        $operation = $this->operationOwnedBy($this->dorn, self::OCHRE_BELT_LABEL, null, '3a6c9e2b5d')->setVisibility(MiningOperationVisibility::Private);
        $this->addMembership($operation, $this->dorn, MiningOperationMemberRole::Owner, MiningOperationMemberStatus::Accepted, ['Dorn Achar']);

        $this->assertPatchRefused($operation, ['corporationCutPercent' => 5], 'A corporation cut needs a known corporation', $this->dorn);
    }

    public function testPendingMemberCannotBeTheBooster(): void
    {
        $operation = $this->e1Operation();
        $elia = $this->addMembership($operation, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Elia Varr']);

        $this->assertPatchRefused($operation, ['boosterMemberId' => $elia->getId()->toRfc4122()], 'The booster must be an accepted participant');
    }

    public function testMemberOfAnotherOperationCannotBeTheBooster(): void
    {
        $operation = $this->e1Operation();
        $iceBelt = $this->iceBeltOperation();
        $cyraOnTheIceBelt = $this->addMembership($iceBelt, $this->cyra, MiningOperationMemberRole::Owner, MiningOperationMemberStatus::Accepted, []);

        $this->assertPatchRefused($operation, ['boosterMemberId' => $cyraOnTheIceBelt->getId()->toRfc4122()], 'The booster must be an accepted participant');
    }

    public function testUnknownMemberCannotBeTheBooster(): void
    {
        $this->assertPatchRefused($this->e1Operation(), ['boosterMemberId' => Uuid::v4()->toRfc4122()], 'The booster must be an accepted participant');
    }

    public function testPlainMemberCannotChangeTheSettings(): void
    {
        $operation = $this->e1Operation();
        $this->actAs($this->brann);
        $this->expectNothingChanged();

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Only the operation owner or an admin can perform this action');

        $this->patch($operation, ['boosterCutPercent' => 10]);
    }

    public function testFrozenSharesKeepTheirSettings(): void
    {
        $operation = $this->e1Operation();
        $operation->setStatus(MiningOperationStatus::Shares);

        $this->assertPatchRefused($operation, ['boosterCutPercent' => 10], 'The shares of this operation are frozen');
    }

    public function testSettingsCanChangeBeforeTheOperationIsClosing(): void
    {
        $operation = $this->startedSpodumainOperation();
        $this->actAs($this->aria);

        self::assertSame(10, $this->patch($operation, ['boosterCutPercent' => 10])->settings['boosterCutPercent'] ?? null);
    }

    public function testEachForThemselvesOperationRefusesABoosterCut(): void
    {
        $this->assertPatchRefused($this->e1Operation(splitMode: MiningSplitMode::EachForThemselves), ['boosterCutPercent' => 10], 'An each-for-themselves operation has no booster or corporation cut');
    }

    public function testEachForThemselvesOperationRefusesABooster(): void
    {
        $operation = $this->e1Operation(splitMode: MiningSplitMode::EachForThemselves);

        $this->assertPatchRefused($operation, ['boosterMemberId' => $this->memberIdOf($operation, $this->cyra)], 'An each-for-themselves operation has no booster or corporation cut');
    }

    public function testEachForThemselvesOperationRevaluesItsMinersAtTheRawPrice(): void
    {
        $operation = $this->e1Operation(splitMode: MiningSplitMode::EachForThemselves);
        $this->actAs($this->aria);

        $valuation = $this->patch($operation, ['valuationForm' => 'raw', 'valuationSide' => 'sell']);

        self::assertSame(
            ['valuationForm' => 'raw', 'ariaMinedValue' => 2_549_929],
            ['valuationForm' => $valuation->settings['valuationForm'] ?? null, 'ariaMinedValue' => $valuation->participants[0]['minedValue'] ?? null],
        );
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function patch(MiningOperation $operation, array $fields): MiningOperationValuationResource
    {
        $input = new UpdateMiningOperationValuationInput();
        foreach ($fields as $field => $value) {
            $input->{$field} = $value;
        }

        return $this->processor->process($input, new Patch(), ['operationId' => $operation->getId()->toRfc4122()]);
    }

    private function assertPatchRefused(MiningOperation $operation, array $fields, string $message, ?User $user = null): void
    {
        $this->actAs($user ?? $this->aria);
        $settingsBefore = $this->settingsOf($operation);
        $this->expectNothingChanged();

        try {
            $this->patch($operation, $fields);
        } catch (BadRequestHttpException $refusal) {
            self::assertSame([$message, $settingsBefore], [$refusal->getMessage(), $this->settingsOf($operation)]);

            return;
        }

        self::fail(sprintf('Expected the refusal "%s"', $message));
    }

    private function expectNothingChanged(): void
    {
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');
    }

    private function memberIdOf(MiningOperation $operation, User $user): string
    {
        return $this->membershipOf($operation, $user)->getId()->toRfc4122();
    }

    private function settings(array $changes = []): array
    {
        return array_replace([
            'valuationForm' => 'compressed',
            'valuationMarket' => 'jita',
            'valuationSide' => 'buy',
            'structureId' => null,
            'structureName' => null,
            'reprocessYield' => null,
            'boosterCutPercent' => 0,
            'corporationCutPercent' => 0,
            'boosterMemberId' => null,
            'boosterCharacterName' => null,
        ], $changes);
    }

    private function settingsOf(MiningOperation $operation): array
    {
        return [
            $operation->getValuationForm()->value,
            $operation->getValuationMarket()->value,
            $operation->getValuationSide()->value,
            $operation->getValuationStructureId(),
            $operation->getValuationStructureName(),
            $operation->getReprocessYield(),
            $operation->getBoosterCutPercent(),
            $operation->getCorporationCutPercent(),
            $operation->getBoosterMember(),
        ];
    }
}
