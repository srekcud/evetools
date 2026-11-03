<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningFrozenMinedValue;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Enum\MarketSide;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningSplitMode;
use App\Service\JitaMarketService;
use App\Service\MiningOperation\MinedQuantityCalculator;
use App\Service\MiningOperation\MiningOperationUnitPrices;
use App\Service\MiningOperation\MiningOperationValuation;
use App\Service\MiningOperation\MiningOperationValuator;
use App\Service\MiningOperation\MiningShareCalculator;
use App\Service\OreValueService;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;
use Psr\Clock\ClockInterface;

trait BuildsValuedSpodumainOperation
{
    private const string BRANN_DEPARTED_AT = '2026-11-05T21:05:00+00:00';
    private const string JITA_SYNCED_AT = '2026-11-05T21:00:00+00:00';
    private const string FROZEN_AT = '2026-11-05T21:52:00+00:00';
    private const int ARIA_MINED_VALUE = 2_699_093;
    private const int BRANN_MINED_VALUE = 2_868_460;
    private const int CYRA_MINED_VALUE = 176;
    private const int E1_TOTAL_VALUE = 5_567_729;

    private function e1Operation(
        array $unreadStarts = [],
        array $waitingEnds = [],
        MiningSplitMode $splitMode = MiningSplitMode::ValueProrata,
        array $endLedgers = [],
    ): MiningOperation {
        $operation = $this->startedSpodumainOperation();
        $operation->setSplitMode($splitMode);
        $this->addMembership($operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Cyra Lune']);
        $this->joinedAt($this->membershipOf($operation, $this->brann), '2026-11-04T08:15:00+00:00');
        $this->joinedAt($this->membershipOf($operation, $this->cyra), '2026-11-04T09:30:00+00:00');
        $this->identifyEnrolments();

        $startLedgers = [
            'Aria Solberg' => [$this->entry('2026-11-05', self::VELDSPAR, self::S1, 5_000)],
            'Aria II' => [],
            'Brann Holt' => [$this->entry('2026-11-05', self::BITUMENS, self::S1, 1_000)],
            'Brann II' => [],
            'Cyra Lune' => [$this->entry('2026-11-05', self::VELDSPAR, self::S1, 2)],
        ];
        foreach ($startLedgers as $characterName => $entries) {
            if (in_array($characterName, $unreadStarts, true)) {
                $this->failedStartSnapshot($characterName, MiningLedgerSnapshotStatus::ReconnectRequired);
            } else {
                $this->takenStartSnapshot($characterName, $entries);
            }
        }

        $endLedgers += [
            'Aria Solberg' => [$this->entry('2026-11-05', self::VELDSPAR, self::S1, 105_000)],
            'Aria II' => [$this->entry('2026-11-05', self::BITUMENS, self::S1, 3_333)],
            'Brann Holt' => [$this->entry('2026-11-05', self::BITUMENS, self::S1, 11_001)],
            'Brann II' => [$this->entry('2026-11-05', self::VELDSPAR, self::S1, 33_337)],
            'Cyra Lune' => [$this->entry('2026-11-05', self::VELDSPAR, self::S1, 11)],
        ];

        $this->membershipOf($operation, $this->brann)->depart(new \DateTimeImmutable(self::BRANN_DEPARTED_AT));
        $this->endOf('Brann Holt', $endLedgers['Brann Holt'], $unreadStarts, $waitingEnds, self::BRANN_DEPARTED_AT);
        $this->endOf('Brann II', $endLedgers['Brann II'], $unreadStarts, $waitingEnds, self::BRANN_DEPARTED_AT);

        $operation->end(new \DateTimeImmutable(self::ENDED_AT));
        foreach (['Aria Solberg', 'Aria II', 'Cyra Lune'] as $characterName) {
            $this->endOf($characterName, $endLedgers[$characterName], $unreadStarts, $waitingEnds);
        }

        return $operation;
    }

    private function endOf(string $characterName, array $entries, array $unreadStarts, array $waitingEnds, string $referenceAt = self::ENDED_AT): void
    {
        if (in_array($characterName, $unreadStarts, true)) {
            return;
        }
        if (in_array($characterName, $waitingEnds, true)) {
            $this->waitingEndSnapshot($characterName, '2026-11-05T21:59:00+00:00', $referenceAt);

            return;
        }
        $this->takenEndSnapshot($characterName, $entries, '2026-11-05T21:42:00+00:00', $referenceAt);
    }

    private function joinedAt(MiningOperationMember $member, string $joinedAt): void
    {
        (new \ReflectionProperty(MiningOperationMember::class, 'joinedAt'))->setValue($member, new \DateTimeImmutable($joinedAt));
    }

    private function withCyraBoostingForTenPercent(MiningOperation $operation, MarketSide $side = MarketSide::Sell): MiningOperation
    {
        $operation->updateValuationSettings(
            valuationSide: $side,
            boosterCutPercent: 10,
            boosterMember: $this->membershipOf($operation, $this->cyra),
        );

        return $operation;
    }

    private function jitaMarketSyncedAt(array $jitaSellPrices, array $jitaBuyPrices = [], ?string $syncedAt = self::JITA_SYNCED_AT): JitaMarketService
    {
        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPrices')->willReturnCallback(
            static fn (array $typeIds): array => self::pricesOf($typeIds, $jitaSellPrices),
        );
        $jitaMarketService->method('getBuyPrices')->willReturnCallback(
            static fn (array $typeIds): array => self::pricesOf($typeIds, $jitaBuyPrices),
        );
        $jitaMarketService->method('getLastSyncTime')->willReturn($syncedAt === null ? null : new \DateTimeImmutable($syncedAt));

        return $jitaMarketService;
    }

    private function oreValueServiceOn(JitaMarketService $jitaMarketService, ?StructureMarketService $structureMarketService = null): OreValueService
    {
        return new OreValueService(
            $this->sdeInvTypeRepository($this->sdeTypes(), []),
            $this->sdeInvTypeMaterialRepository($this->sdeReprocessMaterials()),
            $jitaMarketService,
            $structureMarketService,
        );
    }

    private function unitPricesOn(JitaMarketService $jitaMarketService, ?StructureMarketService $structureMarketService = null): MiningOperationUnitPrices
    {
        $structureMarketService ??= $this->createStub(StructureMarketService::class);

        return new MiningOperationUnitPrices(
            $this->oreValueServiceOn($jitaMarketService, $structureMarketService),
            $jitaMarketService,
            $structureMarketService,
        );
    }

    private function valuatorOn(JitaMarketService $jitaMarketService, ClockInterface $clock): MiningOperationValuator
    {
        return new MiningOperationValuator(
            new MinedQuantityCalculator(),
            $this->unitPricesOn($jitaMarketService),
            new MiningShareCalculator(),
            $clock,
        );
    }

    private function typeNameResolverOfSdeOres(): TypeNameResolver
    {
        $names = array_map(static fn (array $sdeType): string => $sdeType['name'], $this->sdeTypes());
        $resolver = $this->createStub(TypeNameResolver::class);
        $resolver->method('findName')->willReturnCallback(static fn (int $typeId): ?string => $names[$typeId] ?? null);
        $resolver->method('findNames')->willReturnCallback(static fn (array $typeIds): array => array_intersect_key($names, array_flip($typeIds)));

        return $resolver;
    }

    private function deleteAccountOf(MiningOperation $operation, MiningOperationMember $member): void
    {
        foreach ($operation->getFrozenMinedValues() as $frozenMinedValue) {
            if ($frozenMinedValue->getMember() === $member) {
                (new \ReflectionProperty(MiningFrozenMinedValue::class, 'member'))->setValue($frozenMinedValue, null);
            }
        }
        if ($operation->getBoosterMember() === $member) {
            (new \ReflectionProperty(MiningOperation::class, 'boosterMember'))->setValue($operation, null);
        }
        $operation->getMembers()->removeElement($member);
    }

    private function participantValuesOf(MiningOperationValuation $valuation): array
    {
        $values = [];
        foreach ($valuation->participants as $participant) {
            $values[$participant->participantName] = [
                'state' => $participant->quantity?->state->value,
                'minedValue' => $participant->minedValue,
                'share' => $participant->share,
                'roundingAdjustment' => $participant->roundingAdjustment,
            ];
        }

        return $values;
    }

    private function totalsOf(MiningOperationValuation $valuation): array
    {
        return [
            'totalValue' => $valuation->totalValue,
            'boosterCut' => $valuation->split?->boosterCut,
            'corporationCut' => $valuation->split?->corporationCut,
            'roundingRemainder' => $valuation->split?->roundingRemainder,
            'freezeBlockers' => array_map(static fn ($blocker): string => $blocker->value, $valuation->freezeBlockers),
        ];
    }

    private function participantRatesOf(MiningOperationValuation $valuation): array
    {
        $rates = [];
        foreach ($valuation->participants as $participant) {
            $rates[$participant->participantName] = [
                'durationSeconds' => $participant->durationSeconds,
                'iskPerHour' => $participant->iskPerHour === null ? null : round($participant->iskPerHour, 2),
                'valuePercent' => $participant->valuePercent === null ? null : round($participant->valuePercent, 4),
            ];
        }

        return $rates;
    }
}
