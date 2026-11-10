<?php

declare(strict_types=1);

namespace App\Tests\Integration\MiningOperation;

use App\Entity\Sde\DgmTypeAttribute;
use App\Enum\MiningHaulerPlanUnknownReason;
use App\Enum\MiningOperationStatus;
use App\Service\MiningOperation\MiningHaulerPlanReader;
use App\Service\MiningOperation\MiningHaulerPlanReport;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(MiningHaulerPlanReader::class)]
#[CoversClass(MiningHaulerPlanReport::class)]
#[Group('issue-312')]
final class MiningHaulerPlanReaderTest extends IntegrationTestCase
{
    use BuildsHaulerPlanOperation;
    use ClockSensitiveTrait;

    private const float DELTA = 0.000001;

    private MiningHaulerPlanReader $reader;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::HAULER_PLAN_NOW));
        parent::setUp();
        $this->seedHaulerPlanSde();
        $this->reader = self::getContainer()->get(MiningHaulerPlanReader::class);
    }

    public function testRunningOperationWithASiteScanAndALedgerGivesTheKnownPlanFromTheStart(): void
    {
        $report = $this->reader->read($this->persistHaulerPlanOperation());

        self::assertTrue($report->isKnown());
        self::assertSame(
            [
                'unknownReason' => null,
                'boostShipName' => 'Orca',
                'haulerName' => 'Brann Holt',
                'emptiedAt' => null,
                'originIsStart' => true,
                'trips' => 1,
                'siteEtaAt' => '2026-11-10T21:10:00+00:00',
                'rawTypeIds' => [self::ZEOLITES],
                'contributorNames' => ['Aria Solberg', 'Cyra Lune'],
                'isFull' => false,
            ],
            [
                'unknownReason' => $report->unknownReason,
                'boostShipName' => $report->boostShipName,
                'haulerName' => $report->haulerName,
                'emptiedAt' => $report->emptiedAt,
                'originIsStart' => $report->originIsStart,
                'trips' => $report->trips,
                'siteEtaAt' => $report->siteEtaAt?->format(\DateTimeInterface::ATOM),
                'rawTypeIds' => $report->rawTypeIds,
                'contributorNames' => $this->sorted($report->contributorNames),
                'isFull' => $report->isFull,
            ],
        );
        self::assertEqualsWithDelta(
            [
                'capacity' => 40_000.0,
                'hold' => 150_000.0,
                'share' => 0.5,
                'inflow' => 50.0,
                'fill' => 500.0,
                'ratio' => 0.0125,
                'fullIn' => 790.0,
                'transport' => 32.675,
                'toMove' => 2_287.25,
            ],
            [
                'capacity' => $report->capacityM3,
                'hold' => $report->holdCapacityM3,
                'share' => $report->share,
                'inflow' => $report->inflowM3PerMinute,
                'fill' => $report->fillM3,
                'ratio' => $report->fillRatio,
                'fullIn' => $report->fullInMinutes,
                'transport' => $report->transportM3PerMinute,
                'toMove' => $report->toMoveM3,
            ],
            self::DELTA,
        );
    }

    public function testDeclaredEmptyingReplacesTheStartAsTheOriginOfTheFilling(): void
    {
        $operation = $this->persistHaulerPlanOperation();
        $operation->markFleetHangarEmptied(new \DateTimeImmutable('2026-11-10T20:08:00+00:00'));

        $report = $this->reader->read($operation);

        self::assertSame([true, false, '2026-11-10T20:08:00+00:00'], [$report->isKnown(), $report->originIsStart, $report->emptiedAt?->format(\DateTimeInterface::ATOM)]);
        self::assertEqualsWithDelta(
            ['fill' => 100.0, 'ratio' => 0.0025, 'fullIn' => 798.0, 'toMove' => 2_025.85],
            ['fill' => $report->fillM3, 'ratio' => $report->fillRatio, 'fullIn' => $report->fullInMinutes, 'toMove' => $report->toMoveM3],
            self::DELTA,
        );
    }

    public function testOperationThatIsNotRunningIsUnknownAndKeepsTheHullTheHaulerAndTheOrigin(): void
    {
        $report = $this->reader->read($this->persistHaulerPlanOperation(status: MiningOperationStatus::Closing));

        self::assertSame(
            [MiningHaulerPlanUnknownReason::OperationNotRunning, 'Orca', 'Brann Holt', null, true, null, null],
            $this->unknownFigures($report),
        );
    }

    public function testNotRunningComesBeforeEveryOtherReason(): void
    {
        $report = $this->reader->read($this->persistHaulerPlanOperation(status: MiningOperationStatus::Planned, boostShip: null, withRecipient: false, withSite: false));

        self::assertSame(MiningHaulerPlanUnknownReason::OperationNotRunning, $report->unknownReason);
    }

    public function testOperationWithoutBoostShipIsUnknown(): void
    {
        $report = $this->reader->read($this->persistHaulerPlanOperation(boostShip: null));

        self::assertSame([MiningHaulerPlanUnknownReason::NoBoostShip, null, 'Brann Holt', null, true, null, null], $this->unknownFigures($report));
    }

    public function testOperationWithoutFleetStorageRecipientIsUnknown(): void
    {
        $report = $this->reader->read($this->persistHaulerPlanOperation(withRecipient: false));

        self::assertSame([MiningHaulerPlanUnknownReason::NoFleetStorageRecipient, 'Orca', null, null, true, null, null], $this->unknownFigures($report));
    }

    public function testOperationWithoutActiveSiteIsUnknown(): void
    {
        $report = $this->reader->read($this->persistHaulerPlanOperation(withSite: false));

        self::assertSame([MiningHaulerPlanUnknownReason::NoActiveSite, 'Orca', 'Brann Holt', null, true, null, null], $this->unknownFigures($report));
    }

    public function testSiteWhoseTypesHaveNoTrendRateIsUnknown(): void
    {
        $report = $this->reader->read($this->persistHaulerPlanOperation(trendRateByTypeId: [self::VELDSPAR => null, self::SCORDITE => 0.0]));

        self::assertSame(MiningHaulerPlanUnknownReason::SiteRateUnknown, $report->unknownReason);
    }

    public function testUnreadStartSnapshotMakesTheLedgerUnknownForThePlan(): void
    {
        $report = $this->reader->read($this->persistHaulerPlanOperation(readableStartSnapshots: false));

        self::assertSame([MiningHaulerPlanUnknownReason::LedgerUnknown, 'Orca', 'Brann Holt', null, true, null, null], $this->unknownFigures($report));
    }

    public function testBoostShipWithoutHoldCapacityInTheSdeIsUnknown(): void
    {
        $holdCapacity = $this->em->find(DgmTypeAttribute::class, ['typeId' => self::ORCA, 'attributeId' => self::GENERAL_MINING_HOLD_CAPACITY_ATTRIBUTE]);
        \assert($holdCapacity instanceof DgmTypeAttribute);
        $this->em->flush();
        $this->em->remove($holdCapacity);

        $report = $this->reader->read($this->persistHaulerPlanOperation());

        self::assertSame(MiningHaulerPlanUnknownReason::CapacityMissingFromSde, $report->unknownReason);
    }

    /**
     * @return array{?MiningHaulerPlanUnknownReason, ?string, ?string, ?\DateTimeImmutable, bool, ?float, ?int}
     */
    private function unknownFigures(MiningHaulerPlanReport $report): array
    {
        return [$report->unknownReason, $report->boostShipName, $report->haulerName, $report->emptiedAt, $report->originIsStart, $report->fillM3, $report->trips];
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}
