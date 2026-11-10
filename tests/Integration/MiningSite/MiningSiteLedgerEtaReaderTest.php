<?php

declare(strict_types=1);

namespace App\Tests\Integration\MiningSite;

use App\Entity\MiningOperation;
use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningSiteLedgerEtaUnknownReason;
use App\Service\MiningSite\LedgerSiteEta;
use App\Service\MiningSite\MiningSiteLedgerEtaReader;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\MiningOperation\BuildsHaulerPlanOperation;
use App\Tests\Integration\MiningOperation\ShiftsLedgerFreshness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(MiningSiteLedgerEtaReader::class)]
#[Group('issue-267')]
final class MiningSiteLedgerEtaReaderTest extends IntegrationTestCase
{
    use BuildsHaulerPlanOperation;
    use ClockSensitiveTrait;
    use ShiftsLedgerFreshness;

    private MiningSiteLedgerEtaReader $reader;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::HAULER_PLAN_NOW));
        parent::setUp();
        $this->seedHaulerPlanSde();
        $this->reader = self::getContainer()->get(MiningSiteLedgerEtaReader::class);
    }

    public function testSingleScanSiteOfARunningOperationGetsTheLatestEtaFromTheFleetLedgerSinceTheStart(): void
    {
        $operation = $this->persistHaulerPlanOperation();
        $this->freshenLedgerEntries(['Aria Solberg' => '2026-11-10T20:04:00+00:00', 'Brann Holt' => '2026-11-10T20:10:00+00:00', 'Cyra Lune' => '2026-11-10T20:06:00+00:00']);

        $reading = $this->reader->read($this->firstSiteOf($operation));

        self::assertSame(['2026-11-11T16:59:00+00:00', null], $this->figuresOf($reading));
    }

    public function testOperationThatIsNotRunningGivesTheOperationNotRunningReason(): void
    {
        $operation = $this->persistHaulerPlanOperation(status: MiningOperationStatus::Closing);

        $reading = $this->reader->read($this->firstSiteOf($operation));

        self::assertSame([null, 'operation_not_running'], $this->figuresOf($reading));
    }

    public function testSiteOpenedAfterTheFirstOneOfTheOperationGivesTheLaterSiteReason(): void
    {
        $operation = $this->persistHaulerPlanOperation();
        $laterSite = $this->persistSingleScanSite($operation, '2026-11-10T20:40:00+00:00');

        $reading = $this->reader->read($laterSite);

        self::assertSame([null, 'later_site'], $this->figuresOf($reading));
    }

    public function testPersonageWhoseStartSnapshotCannotBeReadGivesTheLedgerUnknownReason(): void
    {
        $operation = $this->persistHaulerPlanOperation(readableStartSnapshots: false);

        $reading = $this->reader->read($this->firstSiteOf($operation));

        self::assertSame([null, 'ledger_unknown'], $this->figuresOf($reading));
    }

    public function testNothingIsShownOnceASecondScanOfTheSiteIsVisible(): void
    {
        $operation = $this->persistHaulerPlanOperation();
        $site = $this->firstSiteOf($operation);
        $this->em->persist((new MiningSiteScan())
            ->setSite($site)
            ->setAuthor($this->aria)
            ->setScannedAt(new \DateTimeImmutable('2026-11-10T20:20:00+00:00'))
            ->setTotalUnits(1_000)
            ->setTotalVolumeM3(100.0)
            ->setRemainingValue(15_000.0)
            ->setTypes([['typeId' => self::VELDSPAR, 'status' => 'present', 'units' => 400_000]]));
        $this->em->flush();

        self::assertNull($this->reader->read($site));
    }

    public function testSiteWithoutOperationHasNothingToShow(): void
    {
        $site = (new MiningSite())->setOwner($this->persistMiningPilot($this->em, 'Free Miner', self::C1))->setLabel('Free belt')->setCreatedAt(new \DateTimeImmutable(self::HAULER_PLAN_STARTED_AT));
        $this->em->persist($site);
        $this->em->flush();

        self::assertNull($this->reader->read($site));
    }

    /**
     * @return array{?string, ?string}
     */
    private function figuresOf(?LedgerSiteEta $reading): array
    {
        self::assertNotNull($reading);

        return [$reading->eta?->format(\DateTimeInterface::ATOM), $reading->unknownReason?->value];
    }

    private function firstSiteOf(MiningOperation $operation): MiningSite
    {
        $site = $this->em->getRepository(MiningSite::class)->findOneBy(['operation' => $operation]);
        \assert($site instanceof MiningSite);

        return $site;
    }

    private function persistSingleScanSite(MiningOperation $operation, string $createdAt): MiningSite
    {
        $site = (new MiningSite())->setOwner($this->aria)->setOperation($operation)->setLabel('Second belt')->setSolarSystemId(self::ALDRANETTE)->setCreatedAt(new \DateTimeImmutable($createdAt));
        $this->em->persist($site);
        $this->em->persist((new MiningSiteScan())
            ->setSite($site)
            ->setAuthor($this->aria)
            ->setScannedAt(new \DateTimeImmutable($createdAt))
            ->setTotalUnits(1_000)
            ->setTotalVolumeM3(100.0)
            ->setRemainingValue(15_000.0)
            ->setTypes([['typeId' => self::VELDSPAR, 'status' => 'present', 'units' => 500_000]]));
        $this->em->flush();

        return $site;
    }
}
