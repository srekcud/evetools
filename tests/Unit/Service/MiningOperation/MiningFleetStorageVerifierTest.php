<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningFleetStorageDelivery;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningSplitMode;
use App\Service\Assets\CorporationContainerContents;
use App\Service\MiningOperation\MiningFleetStorageVerification;
use App\Service\MiningOperation\MiningFleetStorageVerificationLine;
use App\Service\MiningOperation\MiningFleetStorageVerifier;
use App\Service\MiningOperation\MiningShareFreezer;
use App\Service\OreValueService;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(MiningFleetStorageVerifier::class)]
#[CoversClass(MiningFleetStorageVerification::class)]
#[CoversClass(CorporationContainerContents::class)]
#[Group('issue-266')]
#[AllowMockObjectsWithoutExpectations]
final class MiningFleetStorageVerifierTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;
    use BuildsVisibleCorporationAssets;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->setUpVisibleCorporationAssets();
    }

    public function testDeliveredVeldsparFoundCompressedAndRawInTheNamedContainerIsVerified(): void
    {
        $operation = $this->fs2Operation();
        $this->opsHaulHolding([self::COMPRESSED_VELDSPAR => 60_000, self::VELDSPAR => 40_000]);

        self::assertSame(
            $this->verification('verified', null, self::ASSETS_READ_AT, [[self::VELDSPAR, 'Veldspar', 100_000, 100_000, 0, 'verified']]),
            $this->verificationOf($operation, $this->aria),
        );
    }

    public function testMissingVeldsparIsAShortfallOfTheMissingUnits(): void
    {
        $operation = $this->fs2Operation();
        $this->opsHaulHolding([self::COMPRESSED_VELDSPAR => 96_400]);

        self::assertSame(
            $this->verification('short', null, self::ASSETS_READ_AT, [[self::VELDSPAR, 'Veldspar', 100_000, 96_400, 3_600, 'short']]),
            $this->verificationOf($operation, $this->aria),
        );
    }

    public function testSurplusInTheContainerIsNoShortfall(): void
    {
        $operation = $this->fs2Operation();
        $this->opsHaulHolding([self::VELDSPAR => 120_000]);

        self::assertSame(
            $this->verification('verified', null, self::ASSETS_READ_AT, [[self::VELDSPAR, 'Veldspar', 100_000, 120_000, 0, 'verified']]),
            $this->verificationOf($operation, $this->aria),
        );
    }

    public function testEveryContainerOfThatNameIsCounted(): void
    {
        $operation = $this->fs2Operation();
        $this->hangarAssets = [$this->container(self::OPS_HAUL_ITEM_ID, self::CONTAINER_NAME), $this->container(self::SECOND_OPS_HAUL_ITEM_ID, self::CONTAINER_NAME)];
        $this->containerContents = [$this->item(self::VELDSPAR, 50_000, self::OPS_HAUL_ITEM_ID), $this->item(self::VELDSPAR, 50_000, self::SECOND_OPS_HAUL_ITEM_ID)];

        self::assertSame(
            $this->verification('verified', null, self::ASSETS_READ_AT, [[self::VELDSPAR, 'Veldspar', 100_000, 100_000, 0, 'verified']]),
            $this->verificationOf($operation, $this->aria),
        );
    }

    public function testOreInASubContainerOfTheNamedContainerIsCounted(): void
    {
        $operation = $this->fs2Operation();
        $this->hangarAssets = [$this->container(self::OPS_HAUL_ITEM_ID, self::CONTAINER_NAME)];
        $this->containerContents = [$this->container(self::BIN_ITEM_ID, 'Bin', self::OPS_HAUL_ITEM_ID), $this->item(self::VELDSPAR, 100_000, self::BIN_ITEM_ID)];

        self::assertSame(
            $this->verification('verified', null, self::ASSETS_READ_AT, [[self::VELDSPAR, 'Veldspar', 100_000, 100_000, 0, 'verified']]),
            $this->verificationOf($operation, $this->aria),
        );
    }

    public function testEverythingDeliveredGivesOneLinePerMinedTypeSortedByTypeName(): void
    {
        $operation = $this->fs2Operation();
        $this->declare($operation, $this->aria, MiningFleetStorageDelivery::All);
        $this->opsHaulHolding([self::VELDSPAR => 100_000, self::COMPRESSED_BITUMENS => 3_000]);

        self::assertSame(
            $this->verification('short', null, self::ASSETS_READ_AT, [
                [self::BITUMENS, 'Bitumens', 3_333, 3_000, 333, 'short'],
                [self::VELDSPAR, 'Veldspar', 100_000, 100_000, 0, 'verified'],
            ]),
            $this->verificationOf($operation, $this->aria),
        );
    }

    public function testDeliveriesOfSeveralDepositorsAddUp(): void
    {
        $operation = $this->fs2Operation();
        $this->declare($operation, $this->cyra, MiningFleetStorageDelivery::All);
        $this->opsHaulHolding([self::COMPRESSED_VELDSPAR => 100_009]);

        self::assertSame(
            $this->verification('verified', null, self::ASSETS_READ_AT, [[self::VELDSPAR, 'Veldspar', 100_009, 100_009, 0, 'verified']]),
            $this->verificationOf($operation, $this->aria),
        );
    }

    public function testContainerNamedWithAnotherCaseIsNotFound(): void
    {
        $operation = $this->fs2Operation();
        $this->hangarAssets = [$this->container(self::OPS_HAUL_ITEM_ID, 'ops-haul')];
        $this->containerContents = [$this->item(self::VELDSPAR, 100_000, self::OPS_HAUL_ITEM_ID)];

        self::assertSame(
            $this->verification('unknown', 'container_not_found', self::ASSETS_READ_AT, [[self::VELDSPAR, 'Veldspar', 100_000, null, null, 'unknown']]),
            $this->verificationOf($operation, $this->aria),
        );
    }

    public function testCorporationAssetsReadBeforeTheEndOfTheOperationLeaveTheCheckUnknown(): void
    {
        $operation = $this->fs2Operation();
        $this->opsHaulHolding([self::VELDSPAR => 100_000], self::ASSETS_READ_BEFORE_END_AT);

        self::assertSame(
            $this->verification('unknown', 'assets_synced_before_end', self::ASSETS_READ_BEFORE_END_AT, [[self::VELDSPAR, 'Veldspar', 100_000, null, null, 'unknown']]),
            $this->verificationOf($operation, $this->aria),
        );
    }

    public function testCorporationAssetsNeverSyncedLeaveTheCheckUnknown(): void
    {
        $operation = $this->fs2Operation();
        $this->corporationAssetsSynced = false;

        self::assertSame(
            $this->verification('unknown', 'corporation_assets_not_synced', null, [[self::VELDSPAR, 'Veldspar', 100_000, null, null, 'unknown']]),
            $this->verificationOf($operation, $this->aria),
        );
    }

    public function testCorporationWithoutVisibilitySettingLeavesTheCheckUnknown(): void
    {
        $operation = $this->fs2Operation();
        $this->visibility = null;
        $this->opsHaulHolding([self::VELDSPAR => 100_000]);

        self::assertSame(
            $this->verification('unknown', 'no_visible_division', null, [[self::VELDSPAR, 'Veldspar', 100_000, null, null, 'unknown']]),
            $this->verificationOf($operation, $this->aria),
        );
    }

    public function testCorporationSharingNoDivisionLeavesTheCheckUnknown(): void
    {
        $operation = $this->fs2Operation();
        $this->visibility?->setVisibleDivisions([]);
        $this->opsHaulHolding([self::VELDSPAR => 100_000]);

        self::assertSame(
            $this->verification('unknown', 'no_visible_division', null, [[self::VELDSPAR, 'Veldspar', 100_000, null, null, 'unknown']]),
            $this->verificationOf($operation, $this->aria),
        );
    }

    public function testReaderWhoseMainCharacterBelongsToAnotherCorporationDoesNotSeeTheFoundQuantities(): void
    {
        $operation = $this->fs2Operation();
        $this->character('Brann Holt')->setCorporationId(self::C2);
        $this->opsHaulHolding([self::VELDSPAR => 100_000]);

        self::assertSame(
            $this->verification('unknown', 'not_corporation_member', null, [[self::VELDSPAR, 'Veldspar', 100_000, null, null, 'unknown']]),
            $this->verificationOf($operation, $this->brann),
        );
    }

    public function testOperationWithoutContainerNameHasNothingToCheck(): void
    {
        $operation = $this->fs2Operation();
        $operation->assignFleetStorage($this->membershipOf($operation, $this->brann), null);
        $this->opsHaulHolding([self::VELDSPAR => 100_000]);

        self::assertSame(
            $this->verification('unknown', 'no_container_name', null, [[self::VELDSPAR, 'Veldspar', 100_000, null, null, 'unknown']], null),
            $this->verificationOf($operation, $this->aria),
        );
    }

    public function testOperationWithoutCorporationLeavesTheCheckUnknown(): void
    {
        $operation = $this->fs2Operation();
        $operation->setCorporationId(null);
        $this->opsHaulHolding([self::VELDSPAR => 100_000]);

        self::assertSame(
            $this->verification('unknown', 'no_corporation', null, [[self::VELDSPAR, 'Veldspar', 100_000, null, null, 'unknown']]),
            $this->verificationOf($operation, $this->aria),
        );
    }

    public function testCheckStartsOnceTheOperationHasEnded(): void
    {
        $operation = $this->fs2Operation();
        $operation->setStatus(MiningOperationStatus::InProgress);
        $this->opsHaulHolding([self::VELDSPAR => 100_000]);

        self::assertSame(
            $this->verification('unknown', 'operation_not_ended', null, [[self::VELDSPAR, 'Veldspar', 100_000, null, null, 'unknown']]),
            $this->verificationOf($operation, $this->aria),
        );
    }

    public function testArchivedOperationIsNoLongerChecked(): void
    {
        $operation = $this->fs2Operation();
        $operation->setStatus(MiningOperationStatus::Settled);
        $this->opsHaulHolding([self::VELDSPAR => 100_000]);

        self::assertSame(
            $this->verification('unknown', 'operation_archived', null, [[self::VELDSPAR, 'Veldspar', 100_000, null, null, 'unknown']]),
            $this->verificationOf($operation, $this->aria),
        );
    }

    public function testNobodyDeliveringLeavesNothingToCheckWithoutReadingTheCorporationAssets(): void
    {
        $operation = $this->fs2Operation();
        $this->declare($operation, $this->aria, MiningFleetStorageDelivery::None);
        $this->expectNoCorporationAssetRead();

        self::assertSame($this->verification('nothing_delivered', null, null, []), $this->verificationOf($operation, $this->aria));
    }

    public function testEachForThemselvesOperationHasNothingToCheck(): void
    {
        $operation = $this->fs2Operation();
        $operation->setSplitMode(MiningSplitMode::EachForThemselves);
        $this->expectNoCorporationAssetRead();

        self::assertSame($this->verification('nothing_delivered', null, null, []), $this->verificationOf($operation, $this->aria));
    }

    public function testDepositorDeliveringEverythingWithAnUnknownMinedQuantityLeavesTheCheckUnknownWithoutLines(): void
    {
        $operation = $this->fs2Operation(['Aria II']);
        $this->declare($operation, $this->aria, MiningFleetStorageDelivery::All);
        $this->opsHaulHolding([self::VELDSPAR => 100_000]);

        self::assertSame(
            $this->verification('unknown', 'delivered_quantity_unknown', self::ASSETS_READ_AT, []),
            $this->verificationOf($operation, $this->aria),
        );
    }

    public function testFrozenSharesAreStillCheckedLive(): void
    {
        $operation = $this->fs2Operation();
        $clock = new MockClock(self::FROZEN_AT);
        (new MiningShareFreezer(
            $this->valuatorOn($this->jitaMarketSyncedAt($this->jitaSellPrices()), $clock),
            $this->typeNameResolverOfSdeOres(),
            $this->createStub(EntityManagerInterface::class),
            $clock,
        ))->freeze($operation);
        $this->opsHaulHolding([self::COMPRESSED_VELDSPAR => 60_000, self::VELDSPAR => 40_000]);

        self::assertSame(
            ['status' => 'shares', 'verification' => $this->verification('verified', null, self::ASSETS_READ_AT, [[self::VELDSPAR, 'Veldspar', 100_000, 100_000, 0, 'verified']])],
            ['status' => $operation->getStatus()->value, 'verification' => $this->verificationOf($operation, $this->aria)],
        );
    }

    public function testCompressedVariantsAreLookedUpOnceForTheExpectedTypes(): void
    {
        $operation = $this->fs2Operation();
        $this->opsHaulHolding([self::COMPRESSED_VELDSPAR => 100_000]);
        $realOreValueService = $this->oreValueServiceOn($this->jitaMarketSyncedAt($this->jitaSellPrices()));
        $oreValueService = $this->createMock(OreValueService::class);
        $oreValueService->expects($this->once())
            ->method('getBatchOrePrices')
            ->with([self::VELDSPAR])
            ->willReturnCallback(static fn (array $typeIds): array => $realOreValueService->getBatchOrePrices($typeIds));

        $verification = $this->verifier($oreValueService)->verify($operation, $this->aria);

        self::assertSame('verified', $verification->status->value);
    }

    private function verificationOf(MiningOperation $operation, User $reader): array
    {
        $verification = $this->verifier()->verify($operation, $reader);

        return [
            'status' => $verification->status->value,
            'unknownReason' => $verification->unknownReason?->value,
            'containerName' => $verification->containerName,
            'assetsReadAt' => $verification->assetsReadAt?->format(\DateTimeInterface::ATOM),
            'lines' => array_map(static fn (MiningFleetStorageVerificationLine $line): array => [
                $line->typeId,
                $line->typeName,
                $line->expectedQuantity,
                $line->foundQuantity,
                $line->missingQuantity,
                $line->status->value,
            ], $verification->lines),
        ];
    }

    private function verification(string $status, ?string $unknownReason, ?string $assetsReadAt, array $lines, ?string $containerName = self::CONTAINER_NAME): array
    {
        return [
            'status' => $status,
            'unknownReason' => $unknownReason,
            'containerName' => $containerName,
            'assetsReadAt' => $assetsReadAt,
            'lines' => $lines,
        ];
    }
}
