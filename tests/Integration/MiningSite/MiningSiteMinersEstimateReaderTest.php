<?php

declare(strict_types=1);

namespace App\Tests\Integration\MiningSite;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Entity\MiningOperationMember;
use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Entity\User;
use App\Enum\MinersEstimateStatus;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningSiteStatus;
use App\Service\MiningSite\MiningSiteMinersEstimateReader;
use App\Service\MiningSite\TypeMinersEstimate;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\MiningOperation\PersistsMiningOperations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(MiningSiteMinersEstimateReader::class)]
#[Group('issue-309')]
final class MiningSiteMinersEstimateReaderTest extends IntegrationTestCase
{
    use ClockSensitiveTrait;
    use PersistsMiningOperations;

    private const string NOW = '2026-11-10T20:10:00+00:00';
    private const string ENROLLED_AT = '2026-10-01T00:00:00+00:00';
    private const string START = '2026-11-11T09:40:00+00:00';
    private const int VELDSPAR = 1230;
    private const int SCORDITE = 1228;
    private const int PYROXERES = 1224;
    private const int FIRST_SITE_DAY_OFFSET = 20;

    private MiningSiteMinersEstimateReader $reader;
    private User $aria;
    private User $brann;
    private User $cyra;
    private MiningOperation $operation;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        parent::setUp();
        $this->reader = self::getContainer()->get(MiningSiteMinersEstimateReader::class);
        $this->aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1);
        $this->brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1);
        $this->cyra = $this->persistMiningPilot($this->em, 'Cyra Lune', self::C1);
        $this->operation = $this->persistMiningOperation($this->em, $this->aria, 'Anomalie Veldspar', self::START, ownerCharacterNames: ['Aria Solberg']);
        $this->addMiningMembership($this->operation, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $this->addMiningMembership($this->operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Cyra Lune']);
        $this->backdateEnrolments($this->operation);
        $this->em->flush();
    }

    public function testThreeDepletedSitesOfTheOperationGiveTheMeanWorkloadOfTheirDominantType(): void
    {
        $this->persistDepletedSites([60, 90, 120]);

        $estimates = $this->reader->read($this->aria, new \DateTimeImmutable(self::START));

        self::assertSame(
            [[self::VELDSPAR, MinersEstimateStatus::Estimated, 3, 270.0, 4]],
            array_map(static fn (TypeMinersEstimate $estimate): array => [$estimate->typeId, $estimate->needed->status, $estimate->needed->knownSiteCount, $estimate->needed->workMinerMinutes, $estimate->needed->minersNeeded], $estimates),
        );
        self::assertSame([3, 3, 3], array_map(static fn ($site): int => $site->miners, $estimates[0]->sites));
        self::assertEqualsWithDelta(120.0, $estimates[0]->sites[0]->lifespanMinutes, 0.001);
    }

    public function testEverySiteOfTheTypeIsListedWithItsFirstScanAndLabelMostRecentFirst(): void
    {
        $this->persistDepletedSites([60, 90, 120]);

        $estimates = $this->reader->read($this->aria, new \DateTimeImmutable(self::START));
        self::assertCount(1, $estimates);

        self::assertSame(['Site 3', 'Site 2', 'Site 1'], array_map(static fn ($site): string => $site->label, $estimates[0]->sites));
    }

    public function testSitesOutsideTheSampleAreIgnored(): void
    {
        $this->persistDepletedSites([60, 90, 120]);
        $this->persistDepletedSite('Closed by hand', 5, 100, lastUnits: 4_000);
        $this->persistDepletedSite('Free belt', 6, 100, linked: false);
        $this->persistDepletedSite('Too old', 100, 100);
        $this->persistDepletedSite('Of Brann', 7, 100, owner: $this->brann);

        $estimates = $this->reader->read($this->aria, new \DateTimeImmutable(self::START));

        self::assertSame([3], array_map(static fn (TypeMinersEstimate $estimate): int => $estimate->needed->knownSiteCount, $estimates));
        self::assertCount(3, $estimates[0]->sites);
    }

    public function testActiveSiteWhoseLastScanIsEmptyIsNotInTheSample(): void
    {
        $this->persistDepletedSites([60, 90, 120]);
        $this->persistDepletedSite('Still open', 4, 100, status: MiningSiteStatus::Active);

        $estimates = $this->reader->read($this->aria, new \DateTimeImmutable(self::START));
        self::assertCount(1, $estimates);

        self::assertCount(3, $estimates[0]->sites);
    }

    public function testMinersAreTheCharactersPresentBetweenTheFirstAndTheLastScanOfTheSite(): void
    {
        $this->persistDepletedSite('Site 1', 5, 100);
        $this->departMember($this->brann, '-1 hour', 5);
        $this->departMember($this->aria, '+10 minutes', 5);
        $this->addMiningMembership($this->operation, $this->persistMiningPilot($this->em, 'Dorn Achar', null), MiningOperationMemberRole::Member, MiningOperationMemberStatus::Removed, ['Dorn Achar']);
        $this->backdateEnrolments($this->operation);
        $this->enrolAfter($this->cyra, 4);
        $this->em->flush();

        $estimates = $this->reader->read($this->aria, new \DateTimeImmutable(self::START));
        self::assertCount(1, $estimates);

        self::assertSame([1], array_map(static fn ($site): int => $site->miners, $estimates[0]->sites));
    }

    public function testSiteWithoutAnyMinerIsListedWithAnUnknownWorkloadAndStaysOutOfTheMean(): void
    {
        $this->persistDepletedSites([60, 90, 120]);
        $this->persistDepletedSite('Before the crew', 50, 100);

        $estimates = $this->reader->read($this->aria, new \DateTimeImmutable(self::START));
        self::assertCount(1, $estimates);

        self::assertSame([3, 270.0, 4], [$estimates[0]->needed->knownSiteCount, $estimates[0]->needed->workMinerMinutes, \count($estimates[0]->sites)]);
        $unknown = array_values(array_filter($estimates[0]->sites, static fn ($site): bool => $site->label === 'Before the crew'));
        self::assertSame([0, null], [$unknown[0]->miners, $unknown[0]->workMinerMinutes()]);
    }

    public function testEachDominantTypeGetsItsOwnEstimateAndAFewSitesAreNotEnoughHistory(): void
    {
        $this->persistDepletedSites([60, 90, 120]);
        $this->persistDepletedSite('Scordite belt', 3, 80, dominantTypeId: self::SCORDITE);

        $estimates = $this->reader->read($this->aria, new \DateTimeImmutable(self::START));

        $byType = [];
        foreach ($estimates as $estimate) {
            $byType[$estimate->typeId] = [$estimate->needed->status, $estimate->needed->knownSiteCount];
        }
        self::assertSame(
            [self::VELDSPAR => [MinersEstimateStatus::Estimated, 3], self::SCORDITE => [MinersEstimateStatus::InsufficientHistory, 1]],
            $byType,
        );
    }

    public function testUserWithoutAnyDepletedSiteHasNoEstimate(): void
    {
        $this->persistDepletedSites([60, 90, 120]);

        self::assertSame([], $this->reader->read($this->cyra, new \DateTimeImmutable(self::START)));
    }

    /**
     * @param list<int> $lifespanMinutes
     */
    private function persistDepletedSites(array $lifespanMinutes): void
    {
        foreach ($lifespanMinutes as $index => $minutes) {
            $this->persistDepletedSite(sprintf('Site %d', $index + 1), self::FIRST_SITE_DAY_OFFSET - $index * 2, $minutes);
        }
    }

    private function persistDepletedSite(
        string $label,
        int $daysAgo,
        int $lifespanMinutes,
        ?int $dominantTypeId = self::VELDSPAR,
        int $lastUnits = 0,
        bool $linked = true,
        ?User $owner = null,
        MiningSiteStatus $status = MiningSiteStatus::Closed,
    ): MiningSite {
        $first = (new \DateTimeImmutable(self::NOW))->modify(sprintf('-%d days', $daysAgo));
        $last = $first->modify(sprintf('+%d minutes', $lifespanMinutes));
        $site = (new MiningSite())
            ->setOwner($owner ?? $this->aria)
            ->setOperation($linked ? $this->operation : null)
            ->setLabel($label)
            ->setStatus($status)
            ->setCreatedAt($first);
        if ($status === MiningSiteStatus::Closed) {
            $site->setClosedAt($last);
        }
        $this->em->persist($site);
        $this->em->persist($this->scanOf($site, $first, 90_000, [['typeId' => $dominantTypeId, 'status' => 'present', 'units' => 60_000], ['typeId' => self::PYROXERES, 'status' => 'present', 'units' => 30_000]]));
        $this->em->persist($this->scanOf($site, $last, $lastUnits, [['typeId' => $dominantTypeId, 'status' => $lastUnits === 0 ? 'depleted' : 'present', 'units' => $lastUnits]]));
        $this->em->flush();

        return $site;
    }

    /**
     * @param list<array<string, mixed>> $types
     */
    private function scanOf(MiningSite $site, \DateTimeImmutable $at, int $units, array $types): MiningSiteScan
    {
        return (new MiningSiteScan())
            ->setSite($site)
            ->setAuthor($site->getOwner())
            ->setScannedAt($at)
            ->setTotalUnits($units)
            ->setTotalVolumeM3(1_000.0)
            ->setRemainingValue(1_000_000.0)
            ->setTypes($types);
    }

    private function backdateEnrolments(MiningOperation $operation): void
    {
        foreach ($operation->getMembers() as $member) {
            (new \ReflectionProperty(MiningOperationMember::class, 'joinedAt'))->setValue($member, new \DateTimeImmutable(self::ENROLLED_AT));
            foreach ($member->getCharacters() as $enrolment) {
                (new \ReflectionProperty(MiningOperationCharacter::class, 'enrolledAt'))->setValue($enrolment, new \DateTimeImmutable(self::ENROLLED_AT));
            }
        }
    }

    private function departMember(User $user, string $relativeToFirstScan, int $daysAgo): void
    {
        $member = $this->operation->membershipOf($user);
        \assert($member instanceof MiningOperationMember);
        $first = (new \DateTimeImmutable(self::NOW))->modify(sprintf('-%d days', $daysAgo));
        $member->depart($first->modify($relativeToFirstScan));
    }

    private function enrolAfter(User $user, int $daysAgo): void
    {
        $member = $this->operation->membershipOf($user);
        \assert($member instanceof MiningOperationMember);
        foreach ($member->getCharacters() as $enrolment) {
            (new \ReflectionProperty(MiningOperationCharacter::class, 'enrolledAt'))->setValue($enrolment, (new \DateTimeImmutable(self::NOW))->modify(sprintf('-%d days', $daysAgo)));
        }
    }
}
