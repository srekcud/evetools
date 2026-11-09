<?php

declare(strict_types=1);

namespace App\Tests\Integration\MiningSite;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\MiningOperation;
use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningSiteStatus;
use App\Repository\MiningSiteScanRepository;
use App\Service\MiningSite\MiningSiteScanHider;
use App\Service\MiningSite\MiningSiteScanRecorder;
use App\Service\MiningSite\ScanLine;
use App\State\Provider\MiningSite\MiningSiteResourceMapper;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\MiningOperation\PersistsMiningOperations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[CoversClass(MiningSiteScanHider::class)]
#[Group('issue-372')]
final class MiningSiteHiddenScansTest extends IntegrationTestCase
{
    use ClockSensitiveTrait;
    use PersistsMiningOperations;
    use SeedsMiningSiteMarket;

    private const array SERIES = ['20:00:00' => 30_000, '20:10:00' => 25_000, '20:20:00' => 18_000];
    private const int NEXT_SCAN_UNITS = 12_000;

    private MiningSiteScanRecorder $recorder;
    private MiningSiteScanHider $hider;
    private MiningSiteScanRepository $scanRepository;
    private User $aria;
    private User $brann;
    private User $cyra;
    private User $dax;
    private MiningOperation $operation;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:00:00+00:00'));
        parent::setUp();
        $this->replaceJitaBuyMarket();
        $this->seedMiningSiteSde($this->em);
        $this->aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1);
        $this->brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1);
        $this->cyra = $this->persistMiningPilot($this->em, 'Cyra Lune', self::C1);
        $this->dax = $this->persistMiningPilot($this->em, 'Dax Orren', self::C1);
        $this->operation = $this->persistMiningOperation($this->em, $this->aria, 'Anomalie Spodumain — 20:00', '2026-11-05T20:00:00+00:00', ownerCharacterNames: ['Aria Solberg']);
        $this->addMiningMembership($this->operation, $this->brann, MiningOperationMemberRole::Admin, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $this->addMiningMembership($this->operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Cyra Lune']);
        $this->addMiningMembership($this->operation, $this->dax, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Dax Orren']);
        $this->em->flush();
        $this->recorder = self::getContainer()->get(MiningSiteScanRecorder::class);
        $this->hider = self::getContainer()->get(MiningSiteScanHider::class);
        $this->scanRepository = self::getContainer()->get(MiningSiteScanRepository::class);
    }

    public function testNextScanIsEstimatedWithoutTheHiddenScanAndTheRecordedEstimatesAreNotRecomputed(): void
    {
        $withHiddenScan = $this->persistSite('Belt Aldranette');
        $withoutThatScan = $this->persistSite('Belt Jaspet');
        $scans = $this->recordSeries($withHiddenScan, [$this->aria, $this->cyra, $this->cyra]);
        $this->recordSeries($withoutThatScan, [$this->aria, $this->cyra, $this->cyra], skipping: '20:10:00');
        $recordedBefore = $this->recordedEtasOf($withHiddenScan);

        $this->hider->hide($this->cyra, $scans[1]);
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:30:00+00:00'));
        $this->recorder->record($withHiddenScan, [new ScanLine(self::VELDSPAR, self::NEXT_SCAN_UNITS)], $this->aria);
        $this->recorder->record($withoutThatScan, [new ScanLine(self::VELDSPAR, self::NEXT_SCAN_UNITS)], $this->aria);

        $withHiddenEtas = $this->recordedEtasOf($withHiddenScan);
        $withoutThatScanEtas = $this->recordedEtasOf($withoutThatScan);
        self::assertSame(
            [$recordedBefore, $withoutThatScanEtas[2]],
            [\array_slice($withHiddenEtas, 0, 3), $withHiddenEtas[3]],
        );
    }

    public function testHiddenScanLeavesTheNumberingAndTheFirstAndLastScansOfTheSite(): void
    {
        $site = $this->persistSite('Belt Aldranette');
        $scans = $this->recordSeries($site, [$this->aria, $this->aria, $this->aria]);

        $this->hider->hide($this->aria, $scans[0]);
        $this->hider->hide($this->aria, $scans[2]);

        self::assertSame(
            [['20:10:00'], '20:10:00', '20:10:00', 1],
            [
                array_map(fn (MiningSiteScan $scan): string => $scan->getScannedAt()->format('H:i:s'), $this->scanRepository->findBySiteChronological($site)),
                $this->scanRepository->findFirstOf($site)?->getScannedAt()->format('H:i:s'),
                $this->scanRepository->findLastOf($site)?->getScannedAt()->format('H:i:s'),
                $this->scanRepository->countUpTo($site, new \DateTimeImmutable('2026-11-05T21:00:00+00:00')),
            ],
        );
    }

    #[Group('issue-305')]
    public function testHiddenScanIsLeftOutOfTheScanCountAndOfTheExtractedValueBetweenTheFirstAndLastVisibleScans(): void
    {
        $site = $this->persistSite('Belt Aldranette');
        $scans = $this->recordSeries($site, [$this->aria, $this->aria, $this->aria]);
        $mapper = self::getContainer()->get(MiningSiteResourceMapper::class);

        $this->hider->hide($this->aria, $scans[2]);
        $resource = $mapper->siteToResource($site);

        self::assertSame([2, 75_000.0], [$resource->scanCount, $resource->extractedValue]);
    }

    public function testHiddenScanStillOccupiesItsSecond(): void
    {
        $site = $this->persistSite('Belt Aldranette');
        $scans = $this->recordSeries($site, [$this->aria, $this->aria, $this->aria]);
        $this->hider->hide($this->aria, $scans[2]);

        $refused = false;
        try {
            $this->recorder->record($site, [new ScanLine(self::VELDSPAR, self::NEXT_SCAN_UNITS)], $this->aria);
        } catch (ValidationException) {
            $refused = true;
        }

        self::assertTrue($refused);
    }

    public function testHidingTheScanThatClosedTheSiteKeepsTheSiteClosed(): void
    {
        $site = $this->persistSite('Belt Aldranette');
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:00:00+00:00'));
        $first = $this->recorder->record($site, [new ScanLine(self::SCORDITE, 2_000)], $this->aria);
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:10:00+00:00'));
        $this->recorder->record($site, [], $this->aria);
        $last = $this->scanRepository->findLastOf($site);
        \assert($last instanceof MiningSiteScan);

        $this->hider->hide($this->aria, $last);

        self::assertSame([MiningSiteStatus::Closed, $first->getId()], [$site->getStatus(), $this->scanRepository->findLastOf($site)?->getId()]);
    }

    public function testOnlyTheAuthorTheOperationOwnerAndAnAdministratorMayHideAScan(): void
    {
        $site = $this->persistSite('Belt Aldranette', $this->operation);
        $scans = $this->recordSeries($site, [$this->cyra, $this->cyra, $this->cyra]);
        $outcomes = [];

        foreach ([[$this->dax, 0], [$this->cyra, 0], [$this->aria, 1], [$this->brann, 2]] as [$remover, $scanIndex]) {
            try {
                $this->hider->hide($remover, $scans[$scanIndex]);
                $outcomes[$remover->getMainCharacter()?->getName()] = 'hidden';
            } catch (AccessDeniedHttpException) {
                $outcomes[$remover->getMainCharacter()?->getName()] = 'denied';
            }
        }

        self::assertSame(['Dax Orren' => 'denied', 'Cyra Lune' => 'hidden', 'Aria Solberg' => 'hidden', 'Brann Holt' => 'hidden'], $outcomes);
    }

    public function testScanOfAPrivateSiteIsHiddenByItsAuthorOnly(): void
    {
        $site = $this->persistSite('Belt privé');
        $scans = $this->recordSeries($site, [$this->aria, $this->aria, $this->aria]);

        $denied = false;
        try {
            $this->hider->hide($this->brann, $scans[0]);
        } catch (AccessDeniedHttpException) {
            $denied = true;
        }
        $this->hider->hide($this->aria, $scans[0]);

        self::assertSame([true, 2], [$denied, \count($this->scanRepository->findBySiteChronological($site))]);
    }

    public function testScanWithoutAuthorIsHiddenByTheOperationOwnerAndRefusedToAMember(): void
    {
        $site = $this->persistSite('Belt Aldranette', $this->operation);
        $scans = $this->recordSeries($site, [$this->cyra, $this->cyra, $this->cyra]);
        $scans[0]->setAuthor(null);
        $this->em->flush();

        $memberDenied = false;
        try {
            $this->hider->hide($this->dax, $scans[0]);
        } catch (AccessDeniedHttpException) {
            $memberDenied = true;
        }
        $this->hider->hide($this->aria, $scans[0]);

        self::assertSame([true, 2], [$memberDenied, \count($this->scanRepository->findBySiteChronological($site))]);
    }

    private function persistSite(string $label, ?MiningOperation $operation = null): MiningSite
    {
        $site = (new MiningSite())->setOwner($this->aria)->setOperation($operation)->setLabel($label)->setSolarSystemId(self::ALDRANETTE);
        $this->em->persist($site);
        $this->em->flush();

        return $site;
    }

    /**
     * @param list<User> $authors
     *
     * @return list<MiningSiteScan>
     */
    private function recordSeries(MiningSite $site, array $authors, ?string $skipping = null): array
    {
        $scans = [];
        $authorIndex = 0;
        foreach (self::SERIES as $time => $units) {
            $author = $authors[$authorIndex++];
            if ($time === $skipping) {
                continue;
            }
            self::mockTime(new \DateTimeImmutable('2026-11-05T'.$time.'+00:00'));
            $scans[] = $this->recorder->record($site, [new ScanLine(self::VELDSPAR, $units)], $author);
        }

        return $scans;
    }

    /**
     * @return list<array{0: ?string, 1: ?string}>
     */
    private function recordedEtasOf(MiningSite $site): array
    {
        return array_map(
            fn (array $row): array => [$this->timeOfDay($row['recent_eta']), $this->timeOfDay($row['trend_eta'])],
            $this->em->getConnection()->fetchAllAssociative('SELECT recent_eta, trend_eta FROM mining_site_scans WHERE site_id = ? ORDER BY scanned_at', [(string) $site->getId()]),
        );
    }

    private function timeOfDay(?string $instant): ?string
    {
        return $instant === null ? null : (new \DateTimeImmutable($instant, new \DateTimeZone('UTC')))->format('H:i:s');
    }
}
