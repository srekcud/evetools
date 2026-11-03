<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Enum\MiningLedgerSnapshotKind;
use App\Service\MiningOperation\MinedQuantityCalculator;
use App\Service\MiningOperation\MiningOperationValuator;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(MinedQuantityCalculator::class)]
#[CoversClass(MiningOperationValuator::class)]
#[Group('issue-265')]
final class MiningOperationNoEndSnapshotValuationTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;

    private const string START_READ_AFTER_THE_END_AT = '2026-11-05T21:30:05+00:00';

    private MiningOperationValuator $valuator;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->valuator = $this->valuatorOn($this->jitaMarketSyncedAt($this->jitaSellPrices()), new MockClock('2026-11-05T21:50:00+00:00'));
    }

    public function testParticipantWhoseStartIsReadAfterTheEndIsUnknownAndBlocksTheFreeze(): void
    {
        $valuation = $this->valuator->value($this->e1OperationWhereTheStartOfCyraIsReadAfterTheEnd());

        self::assertSame(
            ['cyra' => 'unknown', 'freezeBlockers' => ['quantities_not_known']],
            ['cyra' => $this->participantValuesOf($valuation)['Cyra Lune']['state'] ?? null, 'freezeBlockers' => $this->totalsOf($valuation)['freezeBlockers']],
        );
    }

    public function testCorrectingTheCharacterWithoutEndSnapshotToZeroUnblocksTheFreeze(): void
    {
        $operation = $this->e1OperationWhereTheStartOfCyraIsReadAfterTheEnd();
        $this->enrolmentOf('Cyra Lune')->correct(self::VELDSPAR, null, 0, $this->aria, new \DateTimeImmutable('2026-11-05T21:45:00+00:00'));

        $valuation = $this->valuator->value($operation);

        self::assertSame(
            ['cyra' => ['state' => 'known', 'minedValue' => 0], 'freezeBlockers' => []],
            [
                'cyra' => array_intersect_key($this->participantValuesOf($valuation)['Cyra Lune'] ?? [], ['state' => true, 'minedValue' => true]),
                'freezeBlockers' => $this->totalsOf($valuation)['freezeBlockers'],
            ],
        );
    }

    private function e1OperationWhereTheStartOfCyraIsReadAfterTheEnd(): MiningOperation
    {
        $operation = $this->withCyraBoostingForTenPercent($this->e1Operation(unreadStarts: ['Cyra Lune']));
        $start = $this->enrolmentOf('Cyra Lune')->snapshotOf(MiningLedgerSnapshotKind::Start);
        $start?->schedule(new \DateTimeImmutable(self::ENDED_AT));
        $start?->take(new \DateTimeImmutable(self::START_READ_AFTER_THE_END_AT), $this->reading([$this->entry('2026-11-05', self::VELDSPAR, self::S1, 2)]));

        return $operation;
    }
}
