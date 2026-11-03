<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use App\ApiResource\MiningOperation\MiningOperationValuationResource;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
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
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[CoversClass(MiningOperationValuationProvider::class)]
#[Group('issue-264')]
final class MiningOperationValuationProviderTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;

    private const string READ_AT = '2026-11-05T21:50:00+00:00';

    private Security&Stub $security;
    private MockClock $clock;
    private MiningOperationValuator $valuator;
    private MiningOperationValuationProvider $provider;
    private MiningOperation $operation;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->security = $this->createStub(Security::class);
        $this->clock = new MockClock(self::READ_AT);
        $this->valuator = $this->valuatorOn($this->jitaMarketSyncedAt($this->jitaSellPrices()), $this->clock);
        $this->provider = new MiningOperationValuationProvider(
            $this->security,
            $this->operationRepositoryReadingOperations(),
            new MiningOperationAccess(),
            $this->valuator,
            $this->typeNameResolverOfSdeOres(),
            new MiningOperationResourceMapper(),
        );
    }

    public function testOwnerReadsTheClosingPreviewOfE1AndMayFreezeIt(): void
    {
        $this->closingE1();
        $this->actAs($this->aria);

        $valuation = $this->provide();

        self::assertSame(
            [
                'operationId' => $this->operation->getId()->toRfc4122(),
                'status' => 'closing',
                'splitMode' => 'value_prorata',
                'frozen' => false,
                'sharesFrozenAt' => null,
                'pricesReadAt' => self::READ_AT,
                'pricesSyncedAt' => self::JITA_SYNCED_AT,
                'settings' => [
                    'valuationForm' => 'compressed',
                    'valuationMarket' => 'jita',
                    'valuationSide' => 'sell',
                    'structureId' => null,
                    'structureName' => null,
                    'reprocessYield' => null,
                    'boosterCutPercent' => 10,
                    'corporationCutPercent' => 0,
                    'boosterMemberId' => $this->memberIdOf($this->cyra),
                    'boosterCharacterName' => 'Cyra Lune',
                ],
                'unitPrices' => [
                    ['typeId' => self::BITUMENS, 'typeName' => 'Bitumens', 'unitPrice' => 221.45],
                    ['typeId' => self::VELDSPAR, 'typeName' => 'Veldspar', 'unitPrice' => 19.61],
                ],
                'missingPriceTypeIds' => [],
                'totalValue' => self::E1_TOTAL_VALUE,
                'boosterCut' => 556_773,
                'corporationCut' => 0,
                'iskPerHour' => 3_711_819.33,
                'durationSeconds' => 5_400,
                'roundingRemainder' => 2,
                'boosterRoundingAdjustment' => 1,
                'corporationRoundingAdjustment' => 0,
                'canFreeze' => true,
                'freezeBlockers' => [],
            ],
            $this->operationPartOf($valuation),
        );
    }

    public function testEachParticipantComesInLedgerOrderWithItsValueShareRateAndCharacters(): void
    {
        $this->closingE1();
        $this->actAs($this->aria);

        $participants = $this->roundedParticipantsOf($this->provide());

        self::assertSame(
            [
                [
                    'memberId' => $this->memberIdOf($this->aria),
                    'characterName' => 'Aria Solberg',
                    'isBooster' => false,
                    'quantityState' => 'known',
                    'totalQuantity' => 103_333,
                    'minedValue' => self::ARIA_MINED_VALUE,
                    'valuePercent' => 48.4774,
                    'share' => 2_429_184,
                    'roundingAdjustment' => 1,
                    'iskPerHour' => 1_799_395.33,
                    'durationSeconds' => 5_400,
                    'departedAt' => null,
                    'characters' => [
                        ['id' => $this->enrolmentOf('Aria II')->getId()->toRfc4122(), 'name' => 'Aria II', 'quantityState' => 'known', 'totalQuantity' => 3_333, 'minedValue' => 738_093],
                        ['id' => $this->enrolmentOf('Aria Solberg')->getId()->toRfc4122(), 'name' => 'Aria Solberg', 'quantityState' => 'known', 'totalQuantity' => 100_000, 'minedValue' => 1_961_000],
                    ],
                ],
                [
                    'memberId' => $this->memberIdOf($this->brann),
                    'characterName' => 'Brann Holt',
                    'isBooster' => false,
                    'quantityState' => 'known',
                    'totalQuantity' => 43_338,
                    'minedValue' => self::BRANN_MINED_VALUE,
                    'valuePercent' => 51.5194,
                    'share' => 2_581_614,
                    'roundingAdjustment' => 0,
                    'iskPerHour' => 2_647_809.23,
                    'durationSeconds' => 3_900,
                    'departedAt' => self::BRANN_DEPARTED_AT,
                    'characters' => [
                        ['id' => $this->enrolmentOf('Brann Holt')->getId()->toRfc4122(), 'name' => 'Brann Holt', 'quantityState' => 'known', 'totalQuantity' => 10_001, 'minedValue' => 2_214_721],
                        ['id' => $this->enrolmentOf('Brann II')->getId()->toRfc4122(), 'name' => 'Brann II', 'quantityState' => 'known', 'totalQuantity' => 33_337, 'minedValue' => 653_739],
                    ],
                ],
                [
                    'memberId' => $this->memberIdOf($this->cyra),
                    'characterName' => 'Cyra Lune',
                    'isBooster' => true,
                    'quantityState' => 'known',
                    'totalQuantity' => 9,
                    'minedValue' => self::CYRA_MINED_VALUE,
                    'valuePercent' => 0.0032,
                    'share' => 158,
                    'roundingAdjustment' => 0,
                    'iskPerHour' => 117.33,
                    'durationSeconds' => 5_400,
                    'departedAt' => null,
                    'characters' => [
                        ['id' => $this->enrolmentOf('Cyra Lune')->getId()->toRfc4122(), 'name' => 'Cyra Lune', 'quantityState' => 'known', 'totalQuantity' => 9, 'minedValue' => 176],
                    ],
                ],
            ],
            $participants,
        );
    }

    public function testPlainMemberReadsTheSameBlockersWithoutTheRightToFreeze(): void
    {
        $this->closingE1(unreadStarts: ['Brann II']);
        $this->actAs($this->brann);

        $valuation = $this->provide();

        self::assertSame([false, ['quantities_not_known']], [$valuation->canFreeze, $valuation->freezeBlockers]);
    }

    public function testUnknownQuantityLeavesTheTotalTheCutsAndEveryShareUnknown(): void
    {
        $this->closingE1(unreadStarts: ['Brann II']);
        $this->actAs($this->aria);

        $valuation = $this->provide();

        self::assertSame(
            [
                'totals' => [null, null, null, null, null, null, null],
                'shares' => [null, null, null],
                'valuePercents' => [null, null, null],
                'minedValues' => [self::ARIA_MINED_VALUE, null, self::CYRA_MINED_VALUE],
                'canFreeze' => false,
            ],
            [
                'totals' => [$valuation->totalValue, $valuation->boosterCut, $valuation->corporationCut, $valuation->iskPerHour, $valuation->roundingRemainder, $valuation->boosterRoundingAdjustment, $valuation->corporationRoundingAdjustment],
                'shares' => array_column($valuation->participants, 'share'),
                'valuePercents' => array_column($valuation->participants, 'valuePercent'),
                'minedValues' => array_column($valuation->participants, 'minedValue'),
                'canFreeze' => $valuation->canFreeze,
            ],
        );
    }

    public function testPendingPilotCannotReadTheValuation(): void
    {
        $this->closingE1();
        $this->addMembership($this->operation, $this->elia, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Elia Varr']);
        $this->actAs($this->elia);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this operation');

        $this->provide();
    }

    public function testPlainMemberCannotReachTheSettingsUpdate(): void
    {
        $this->closingE1();
        $this->actAs($this->brann);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Only the operation owner or an admin can perform this action');

        $this->provide(new Patch());
    }

    public function testFrozenValuationIsReadAtTheFreezeTime(): void
    {
        $this->closingE1();
        $this->freezeAt(self::FROZEN_AT);
        $this->clock->modify('2026-11-05T22:30:00+00:00');
        $this->actAs($this->aria);

        $valuation = $this->provide();

        self::assertSame(
            ['shares', true, self::FROZEN_AT, self::FROZEN_AT, [2_429_184, 2_581_614, 158], false, ['not_closing']],
            [
                $valuation->status,
                $valuation->frozen,
                $valuation->sharesFrozenAt?->format(\DateTimeInterface::ATOM),
                $valuation->pricesReadAt?->format(\DateTimeInterface::ATOM),
                array_column($valuation->participants, 'share'),
                $valuation->canFreeze,
                $valuation->freezeBlockers,
            ],
        );
    }

    public function testFrozenParticipantWhoseAccountWasDeletedKeepsHisFrozenNameWithoutMember(): void
    {
        $this->closingE1();
        $this->freezeAt(self::FROZEN_AT);
        $this->deleteAccountOf($this->operation, $this->membershipOf($this->operation, $this->brann));
        $this->actAs($this->aria);

        $valuation = $this->provide();

        self::assertSame(
            [[null, 'Brann Holt', self::BRANN_MINED_VALUE, 2_581_614]],
            array_values(array_map(
                static fn (array $participant): array => [$participant['memberId'], $participant['characterName'], $participant['minedValue'], $participant['share']],
                array_filter($valuation->participants, static fn (array $participant): bool => $participant['characterName'] === 'Brann Holt'),
            )),
        );
    }

    private function closingE1(array $unreadStarts = []): void
    {
        $this->operation = $this->withCyraBoostingForTenPercent($this->e1Operation(unreadStarts: $unreadStarts));
    }

    private function freezeAt(string $frozenAt): void
    {
        $clock = new MockClock($frozenAt);
        (new MiningShareFreezer(
            $this->valuatorOn($this->jitaMarketSyncedAt($this->jitaSellPrices()), $clock),
            $this->typeNameResolverOfSdeOres(),
            $this->createStub(EntityManagerInterface::class),
            $clock,
        ))->freeze($this->operation);
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function provide(Operation $apiOperation = new Get()): MiningOperationValuationResource
    {
        return $this->provider->provide($apiOperation, ['operationId' => $this->operation->getId()->toRfc4122()]);
    }

    private function memberIdOf(User $user): string
    {
        return $this->membershipOf($this->operation, $user)->getId()->toRfc4122();
    }

    private function operationPartOf(MiningOperationValuationResource $valuation): array
    {
        return [
            'operationId' => $valuation->operationId,
            'status' => $valuation->status,
            'splitMode' => $valuation->splitMode,
            'frozen' => $valuation->frozen,
            'sharesFrozenAt' => $valuation->sharesFrozenAt?->format(\DateTimeInterface::ATOM),
            'pricesReadAt' => $valuation->pricesReadAt?->format(\DateTimeInterface::ATOM),
            'pricesSyncedAt' => $valuation->pricesSyncedAt?->format(\DateTimeInterface::ATOM),
            'settings' => $valuation->settings,
            'unitPrices' => $valuation->unitPrices,
            'missingPriceTypeIds' => $valuation->missingPriceTypeIds,
            'totalValue' => $valuation->totalValue,
            'boosterCut' => $valuation->boosterCut,
            'corporationCut' => $valuation->corporationCut,
            'iskPerHour' => $valuation->iskPerHour === null ? null : round($valuation->iskPerHour, 2),
            'durationSeconds' => $valuation->durationSeconds,
            'roundingRemainder' => $valuation->roundingRemainder,
            'boosterRoundingAdjustment' => $valuation->boosterRoundingAdjustment,
            'corporationRoundingAdjustment' => $valuation->corporationRoundingAdjustment,
            'canFreeze' => $valuation->canFreeze,
            'freezeBlockers' => $valuation->freezeBlockers,
        ];
    }

    private function roundedParticipantsOf(MiningOperationValuationResource $valuation): array
    {
        return array_map(static fn (array $participant): array => array_replace($participant, [
            'valuePercent' => $participant['valuePercent'] === null ? null : round($participant['valuePercent'], 4),
            'iskPerHour' => $participant['iskPerHour'] === null ? null : round($participant['iskPerHour'], 2),
        ]), $valuation->participants);
    }
}
