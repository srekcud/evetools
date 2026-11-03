<?php

declare(strict_types=1);

namespace App\Tests\Integration\MiningOperation;

use App\Entity\CorporationMiningExtraction;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\MiningOperationRecurrence;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationRecurrenceKind;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningOperationVisibility;
use App\Enum\MiningSplitMode;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use App\Service\MiningOperation\MiningOperationOccurrenceCreator;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(MiningOperationOccurrenceCreator::class)]
#[Group('issue-310')]
final class MiningOperationOccurrenceCreatorTest extends IntegrationTestCase
{
    use PersistsMiningOperations;

    private const int ATHANOR_STRUCTURE_ID = 1_001;
    private const int OTHER_STRUCTURE_ID = 1_002;
    private const string LABEL = 'Jaspet de jeudi';

    private MiningOperationOccurrenceCreator $creator;
    private User $aria;
    private User $brann;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creator = self::getContainer()->get(MiningOperationOccurrenceCreator::class);
        $this->aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1, ['Aria II']);
        $this->brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1);
    }

    public function testThursdayOccurrenceIsCreatedAsPlannedOperationOfTheOwnerWithItsEligibleCharactersAndAFreshJoinCode(): void
    {
        $recurrence = $this->weeklyRecurrence('2026-11-05T18:00:00+00:00');
        $initial = $this->recurringOperation($recurrence, '2026-11-05T20:00:00+00:00');

        $created = $this->createDueAt($recurrence, '2026-11-05T20:05:00+00:00');

        self::assertCount(1, $created);
        $occurrence = $created[0];
        self::assertSame(
            [MiningOperationStatus::Planned, '2026-11-12T20:00:00+00:00', self::LABEL, MiningOperationVisibility::Corporation, MiningSplitMode::ValueProrata, self::C1, null],
            [$occurrence->getStatus(), $occurrence->getPlannedStartAt()->format(DATE_ATOM), $occurrence->getLabel(), $occurrence->getVisibility(), $occurrence->getSplitMode(), $occurrence->getCorporationId(), $occurrence->getChunk()],
        );
        self::assertSame($recurrence, $occurrence->getRecurrence());
        self::assertSame($this->aria, $occurrence->getOwner());
        self::assertNotSame($initial->getJoinCode(), $occurrence->getJoinCode());
        $ownerMembership = $occurrence->membershipOf($this->aria);
        self::assertSame([MiningOperationMemberRole::Owner, MiningOperationMemberStatus::Accepted], [$ownerMembership?->getRole(), $ownerMembership?->getStatus()]);
        self::assertSame(['Aria II', 'Aria Solberg'], $this->enrolledNamesOf($ownerMembership));
    }

    public function testNothingIsCreatedTheDayBeforeTheLastOccurrence(): void
    {
        $recurrence = $this->weeklyRecurrence('2026-11-05T18:00:00+00:00');
        $this->recurringOperation($recurrence, '2026-11-05T20:00:00+00:00');

        $created = $this->createDueAt($recurrence, '2026-11-04T12:00:00+00:00');

        self::assertSame([], $created);
        self::assertSame(1, $this->operationsCountOf($recurrence));
    }

    public function testSecondPassRightAfterTheFirstCreatesNoSecondOccurrence(): void
    {
        $recurrence = $this->weeklyRecurrence('2026-11-05T18:00:00+00:00');
        $this->recurringOperation($recurrence, '2026-11-05T20:00:00+00:00');
        $this->createDueAt($recurrence, '2026-11-05T20:05:00+00:00');

        $secondPass = $this->createDueAt($recurrence, '2026-11-05T20:06:00+00:00');

        self::assertSame([], $secondPass);
        self::assertSame(2, $this->operationsCountOf($recurrence));
    }

    public function testSchedulerDownForThreeThursdaysCreatesOnlyTheNextFutureOccurrence(): void
    {
        $recurrence = $this->weeklyRecurrence('2026-11-05T18:00:00+00:00');
        $this->recurringOperation($recurrence, '2026-11-05T20:00:00+00:00');

        $created = $this->createDueAt($recurrence, '2026-11-26T21:00:00+00:00');

        self::assertSame(['2026-12-03T20:00:00+00:00'], array_map(static fn (MiningOperation $operation): string => $operation->getPlannedStartAt()->format(DATE_ATOM), $created));
        self::assertSame(2, $this->operationsCountOf($recurrence));
    }

    public function testSingleChunkOfTheCorporationArrivingThatDayIsLinkedToTheWeeklyOccurrence(): void
    {
        $recurrence = $this->weeklyRecurrence('2026-11-05T18:00:00+00:00');
        $this->recurringOperation($recurrence, '2026-11-05T20:00:00+00:00');
        $chunk = $this->extraction(self::C1, self::ATHANOR_STRUCTURE_ID, 11, '2026-11-08T10:00:00+00:00', '2026-11-12T18:30:00+00:00');
        $this->extraction(self::C1, self::ATHANOR_STRUCTURE_ID, 12, '2026-11-08T10:00:00+00:00', '2026-11-13T00:30:00+00:00');

        $created = $this->createDueAt($recurrence, '2026-11-05T20:05:00+00:00');

        self::assertCount(1, $created);
        self::assertSame($chunk, $created[0]->getChunk());
    }

    public function testWeeklyOccurrenceHasNoChunkWhenNoChunkOfItsCorporationArrivesThatDay(): void
    {
        $recurrence = $this->weeklyRecurrence('2026-11-05T18:00:00+00:00');
        $this->recurringOperation($recurrence, '2026-11-05T20:00:00+00:00');
        $this->extraction(self::C2, self::ATHANOR_STRUCTURE_ID, 21, '2026-11-08T10:00:00+00:00', '2026-11-12T18:30:00+00:00');

        $created = $this->createDueAt($recurrence, '2026-11-05T20:05:00+00:00');

        self::assertCount(1, $created);
        self::assertNull($created[0]->getChunk());
    }

    public function testWeeklyOccurrenceHasNoChunkWhenTwoChunksArriveThatDay(): void
    {
        $recurrence = $this->weeklyRecurrence('2026-11-05T18:00:00+00:00');
        $this->recurringOperation($recurrence, '2026-11-05T20:00:00+00:00');
        $this->extraction(self::C1, self::ATHANOR_STRUCTURE_ID, 31, '2026-11-08T10:00:00+00:00', '2026-11-12T08:00:00+00:00');
        $this->extraction(self::C1, self::OTHER_STRUCTURE_ID, 32, '2026-11-08T10:00:00+00:00', '2026-11-12T23:00:00+00:00');

        $created = $this->createDueAt($recurrence, '2026-11-05T20:05:00+00:00');

        self::assertCount(1, $created);
        self::assertNull($created[0]->getChunk());
    }

    public function testChunkRecurrenceCreatesOneOccurrenceAtTheChunkArrivalWithDefaultValuationAndNothingOnRepass(): void
    {
        $recurrence = $this->chunkRecurrence('2026-11-02T12:00:00+00:00');
        $chunk = $this->extraction(self::C1, self::ATHANOR_STRUCTURE_ID, 41, '2026-11-02T18:00:00+00:00', '2026-11-05T20:00:00+00:00');

        $created = $this->createDueAt($recurrence, '2026-11-03T09:00:00+00:00');
        $repass = $this->createDueAt($recurrence, '2026-11-03T09:30:00+00:00');

        self::assertCount(1, $created);
        $occurrence = $created[0];
        self::assertSame(
            ['2026-11-05T20:00:00+00:00', MiningOperationStatus::Planned, MiningValuationForm::Compressed, MiningValuationMarket::Jita, MarketSide::Buy, 0, 0],
            [$occurrence->getPlannedStartAt()->format(DATE_ATOM), $occurrence->getStatus(), $occurrence->getValuationForm(), $occurrence->getValuationMarket(), $occurrence->getValuationSide(), $occurrence->getBoosterCutPercent(), $occurrence->getCorporationCutPercent()],
        );
        self::assertSame([$recurrence, $chunk], [$occurrence->getRecurrence(), $occurrence->getChunk()]);
        self::assertSame([], $repass);
        self::assertSame(1, $this->operationsCountOf($recurrence));
    }

    public function testChunkRecurrenceIgnoresAnExtractionStartedBeforeItWasCreated(): void
    {
        $recurrence = $this->chunkRecurrence('2026-11-02T12:00:00+00:00');
        $this->extraction(self::C1, self::ATHANOR_STRUCTURE_ID, 51, '2026-11-02T11:59:00+00:00', '2026-11-05T20:00:00+00:00');

        self::assertSame([], $this->createDueAt($recurrence, '2026-11-03T09:00:00+00:00'));
    }

    public function testChunkRecurrenceIgnoresTheExtractionsOfAnotherStructureAndOfAnotherCorporation(): void
    {
        $recurrence = $this->chunkRecurrence('2026-11-02T12:00:00+00:00');
        $this->extraction(self::C1, self::OTHER_STRUCTURE_ID, 61, '2026-11-02T18:00:00+00:00', '2026-11-05T20:00:00+00:00');
        $this->extraction(self::C2, self::ATHANOR_STRUCTURE_ID, 62, '2026-11-02T18:00:00+00:00', '2026-11-05T21:00:00+00:00');

        self::assertSame([], $this->createDueAt($recurrence, '2026-11-03T09:00:00+00:00'));
    }

    public function testChunkRecurrenceIgnoresAChunkThatAlreadyArrived(): void
    {
        $recurrence = $this->chunkRecurrence('2026-11-02T12:00:00+00:00');
        $this->extraction(self::C1, self::ATHANOR_STRUCTURE_ID, 71, '2026-11-02T18:00:00+00:00', '2026-11-03T08:59:00+00:00');

        self::assertSame([], $this->createDueAt($recurrence, '2026-11-03T09:00:00+00:00'));
    }

    public function testStoppedRecurrenceCreatesNothingAndKeepsItsExistingOperationsLinked(): void
    {
        $recurrence = $this->weeklyRecurrence('2026-11-05T18:00:00+00:00');
        $initial = $this->recurringOperation($recurrence, '2026-11-05T20:00:00+00:00');
        $recurrence->stop(new \DateTimeImmutable('2026-11-06T08:00:00+00:00'));

        $created = $this->createDueAt($recurrence, '2026-11-12T20:00:00+00:00');

        self::assertSame([], $created);
        self::assertSame([1, $recurrence], [$this->operationsCountOf($recurrence), $initial->getRecurrence()]);
    }

    public function testOccurrenceCopiesTheValuationOfTheLatestOperationAndMakesTheOwnerTheBoosterWhenHeWas(): void
    {
        $recurrence = $this->weeklyRecurrence('2026-11-05T18:00:00+00:00');
        $older = $this->recurringOperation($recurrence, '2026-11-05T20:00:00+00:00');
        $older->updateValuationSettings(MiningValuationForm::Compressed, MiningValuationMarket::Jita, MarketSide::Buy, null, null, null, 3, 1, null);
        $latest = $this->recurringOperation($recurrence, '2026-11-12T20:00:00+00:00');
        $latest->updateValuationSettings(MiningValuationForm::Reprocessed, MiningValuationMarket::Jita, MarketSide::Sell, null, null, 0.82, 10, 5, $latest->membershipOf($this->aria));
        $this->em->flush();

        $created = $this->createDueAt($recurrence, '2026-11-12T20:05:00+00:00');

        self::assertCount(1, $created);
        $occurrence = $created[0];
        self::assertSame('2026-11-19T20:00:00+00:00', $occurrence->getPlannedStartAt()->format(DATE_ATOM));
        self::assertSame(
            [MiningValuationForm::Reprocessed, MiningValuationMarket::Jita, MarketSide::Sell, 0.82, 10, 5],
            [$occurrence->getValuationForm(), $occurrence->getValuationMarket(), $occurrence->getValuationSide(), $occurrence->getReprocessYield(), $occurrence->getBoosterCutPercent(), $occurrence->getCorporationCutPercent()],
        );
        self::assertSame($occurrence->membershipOf($this->aria), $occurrence->getBoosterMember());
    }

    public function testBoosterCutIsCopiedButTheBoosterStaysEmptyWhenAnotherMemberWasTheBooster(): void
    {
        $recurrence = $this->weeklyRecurrence('2026-11-05T18:00:00+00:00');
        $initial = $this->recurringOperation($recurrence, '2026-11-05T20:00:00+00:00');
        $brannMembership = $this->addMiningMembership($initial, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, []);
        $initial->updateValuationSettings(MiningValuationForm::Compressed, MiningValuationMarket::Jita, MarketSide::Sell, null, null, null, 10, 5, $brannMembership);
        $this->em->flush();

        $created = $this->createDueAt($recurrence, '2026-11-05T20:05:00+00:00');

        self::assertCount(1, $created);
        self::assertSame([10, 5, null], [$created[0]->getBoosterCutPercent(), $created[0]->getCorporationCutPercent(), $created[0]->getBoosterMember()]);
    }

    public function testOccurrenceEnrolsOnlyTheOwnerCharactersThatAreEligible(): void
    {
        $recurrence = $this->weeklyRecurrence('2026-11-05T18:00:00+00:00');
        $this->recurringOperation($recurrence, '2026-11-05T20:00:00+00:00');
        $this->persistMiningOperation($this->em, $this->aria, 'Glace de nuit', '2026-11-06T20:00:00+00:00', visibility: MiningOperationVisibility::Private, ownerCharacterNames: ['Aria II']);
        $this->em->flush();

        $created = $this->createDueAt($recurrence, '2026-11-05T20:05:00+00:00');

        self::assertCount(1, $created);
        self::assertSame(['Aria Solberg'], $this->enrolledNamesOf($created[0]->membershipOf($this->aria)));
    }

    private function weeklyRecurrence(string $createdAt): MiningOperationRecurrence
    {
        return $this->recurrence(MiningOperationRecurrenceKind::Weekly, $createdAt, null);
    }

    private function chunkRecurrence(string $createdAt): MiningOperationRecurrence
    {
        return $this->recurrence(MiningOperationRecurrenceKind::Chunk, $createdAt, self::ATHANOR_STRUCTURE_ID);
    }

    private function recurrence(MiningOperationRecurrenceKind $kind, string $createdAt, ?int $structureId): MiningOperationRecurrence
    {
        $recurrence = (new MiningOperationRecurrence())
            ->setOwner($this->aria)
            ->setKind($kind)
            ->setLabel(self::LABEL)
            ->setVisibility(MiningOperationVisibility::Corporation)
            ->setSplitMode(MiningSplitMode::ValueProrata)
            ->setCorporationId(self::C1)
            ->setStructureId($structureId)
            ->setCreatedAt(new \DateTimeImmutable($createdAt));
        $this->em->persist($recurrence);
        $this->em->flush();

        return $recurrence;
    }

    private function recurringOperation(MiningOperationRecurrence $recurrence, string $plannedStartAt): MiningOperation
    {
        $operation = $this->persistMiningOperation($this->em, $this->aria, self::LABEL, $plannedStartAt, MiningOperationStatus::Completed);
        $operation->setRecurrence($recurrence);
        $this->em->flush();

        return $operation;
    }

    private function extraction(int $corporationId, int $structureId, int $moonId, string $extractionStartTime, string $chunkArrivalTime): CorporationMiningExtraction
    {
        $extraction = (new CorporationMiningExtraction())
            ->setCorporationId($corporationId)
            ->setStructureId($structureId)
            ->setMoonId($moonId)
            ->setExtractionStartTime(new \DateTimeImmutable($extractionStartTime))
            ->setChunkArrivalTime(new \DateTimeImmutable($chunkArrivalTime))
            ->setNaturalDecayTime(new \DateTimeImmutable($chunkArrivalTime))
            ->setStructureName(sprintf('Athanor %d', $structureId));
        $this->em->persist($extraction);
        $this->em->flush();

        return $extraction;
    }

    /**
     * @return list<MiningOperation>
     */
    private function createDueAt(MiningOperationRecurrence $recurrence, string $now): array
    {
        return $this->creator->createDueOccurrences($recurrence, new \DateTimeImmutable($now));
    }

    private function operationsCountOf(MiningOperationRecurrence $recurrence): int
    {
        $this->em->flush();
        $this->em->clear();

        return $this->em->getRepository(MiningOperation::class)->count(['recurrence' => $recurrence->getId()]);
    }

    /**
     * @return list<string>
     */
    private function enrolledNamesOf(?MiningOperationMember $membership): array
    {
        $names = array_map(static fn ($enrolment): string => $enrolment->getCharacter()->getName(), $membership?->getCharacters()->toArray() ?? []);
        sort($names);

        return $names;
    }
}
