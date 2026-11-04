<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoonChunk;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\MoonComposition;
use App\Entity\User;
use App\Service\MoonChunk\MoonCompositionRecorder;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(MoonCompositionRecorder::class)]
#[Group('issue-308')]
final class MoonCompositionRecorderTest extends IntegrationTestCase
{
    use ClockSensitiveTrait;
    use SeedsMoonChunkMarket;

    private const int ILYRA_CORPORATION_ID = 98_262_101;
    private const int CYRA_CORPORATION_ID = 98_262_102;
    private const int ILYRA_MOON_ID = 40_301_001;
    private const string FIRST_PASTE_AT = '2026-11-05T20:00:00+00:00';
    private const string SECOND_PASTE_AT = '2026-11-12T21:30:00+00:00';

    private MoonCompositionRecorder $recorder;
    private User $aria;
    private User $brann;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::FIRST_PASTE_AT));
        parent::setUp();
        $this->seedMoonOreSde($this->em);
        $this->aria = $this->createUser();
        $this->brann = $this->createUser();
        $this->em->flush();
        $this->recorder = self::getContainer()->get(MoonCompositionRecorder::class);
    }

    public function testAPastedScanIsStoredForTheCorporationAndMoonWithItsAuthorItsDateAndTheOresResolvedWithoutRegardToCase(): void
    {
        $this->recorder->record($this->aria, self::ILYRA_CORPORATION_ID, self::ILYRA_MOON_ID, "bitumens\t50 %\nCHROMITE\t0,5");

        self::assertSame(
            [[self::ILYRA_CORPORATION_ID, self::ILYRA_MOON_ID, (string) $this->aria->getId(), '2026-11-05 20:00:00', [['typeId' => self::BITUMENS, 'share' => 0.5], ['typeId' => self::CHROMITE, 'share' => 0.5]]]],
            $this->storedCompositions(),
        );
    }

    public function testPastingAgainForTheSameMoonReplacesTheCompositionInsteadOfAddingOne(): void
    {
        $this->recorder->record($this->aria, self::ILYRA_CORPORATION_ID, self::ILYRA_MOON_ID, "Bitumens\t50 %\nChromite\t50 %");
        self::mockTime(new \DateTimeImmutable(self::SECOND_PASTE_AT));

        $this->recorder->record($this->brann, self::ILYRA_CORPORATION_ID, self::ILYRA_MOON_ID, "Zeolites\t30 %\nBitumens\t70 %");

        self::assertSame(
            [[self::ILYRA_CORPORATION_ID, self::ILYRA_MOON_ID, (string) $this->brann->getId(), '2026-11-12 21:30:00', [['typeId' => self::ZEOLITES, 'share' => 0.3], ['typeId' => self::BITUMENS, 'share' => 0.7]]]],
            $this->storedCompositions(),
        );
    }

    public function testTheSameMoonKeepsASeparateCompositionForEachCorporation(): void
    {
        $this->recorder->record($this->aria, self::ILYRA_CORPORATION_ID, self::ILYRA_MOON_ID, "Bitumens\t50 %\nChromite\t50 %");
        $this->recorder->record($this->brann, self::CYRA_CORPORATION_ID, self::ILYRA_MOON_ID, "Zeolites\t100 %");

        self::assertSame(
            [
                [self::ILYRA_CORPORATION_ID, [self::BITUMENS, self::CHROMITE]],
                [self::CYRA_CORPORATION_ID, [self::ZEOLITES]],
            ],
            array_map(static fn (array $row): array => [$row[0], array_column($row[4], 'typeId')], $this->storedCompositions()),
        );
    }

    public static function scansNamingAnOreOutsideTheSde(): iterable
    {
        yield 'name absent from the SDE' => ["Bitumens\t50 %\nUnobtainium\t50 %", 'scan[2]'];
        yield 'type that is not published' => ["Bitumens\t50 %\nCinnabar\t50 %", 'scan[2]'];
        yield 'type outside the asteroid category' => ["Tritanium\t50 %\nBitumens\t50 %", 'scan[1]'];
    }

    #[DataProvider('scansNamingAnOreOutsideTheSde')]
    public function testAScanNamingAnOreOutsideTheSdeIsRefusedOnItsLineAndNothingIsStored(string $scan, string $faultyPropertyPath): void
    {
        $refusal = $this->refusalOf($scan);

        self::assertSame([[$faultyPropertyPath], []], [$refusal, $this->storedCompositions()]);
    }

    public function testAScanWhoseSharesDoNotAddUpToOneIsRefusedAndNothingIsStored(): void
    {
        $refusal = $this->refusalOf("bitumens  0.25\nChromite  0.25");

        self::assertSame([['scan'], []], [$refusal, $this->storedCompositions()]);
    }

    public function testARefusedPasteKeepsTheCompositionAlreadyRecorded(): void
    {
        $this->recorder->record($this->aria, self::ILYRA_CORPORATION_ID, self::ILYRA_MOON_ID, "Bitumens\t50 %\nChromite\t50 %");
        self::mockTime(new \DateTimeImmutable(self::SECOND_PASTE_AT));

        $refusal = $this->refusalOf("Zeolites\t50 %\nUnobtainium\t50 %");

        self::assertSame(
            [['scan[2]'], [[self::ILYRA_CORPORATION_ID, self::ILYRA_MOON_ID, (string) $this->aria->getId(), '2026-11-05 20:00:00', [['typeId' => self::BITUMENS, 'share' => 0.5], ['typeId' => self::CHROMITE, 'share' => 0.5]]]]],
            [$refusal, $this->storedCompositions()],
        );
    }

    public function testTheDatabaseRefusesASecondCompositionForTheSameCorporationAndMoon(): void
    {
        foreach ([self::BITUMENS, self::CHROMITE] as $typeId) {
            $this->em->persist(
                (new MoonComposition())
                    ->setCorporationId(self::ILYRA_CORPORATION_ID)
                    ->setMoonId(self::ILYRA_MOON_ID)
                    ->setRecordedBy($this->aria)
                    ->setRecordedAt(new \DateTimeImmutable(self::FIRST_PASTE_AT))
                    ->setLines([['typeId' => $typeId, 'share' => 1.0]]),
            );
        }

        $this->expectException(UniqueConstraintViolationException::class);

        $this->em->flush();
    }

    private function refusalOf(string $scan): array
    {
        try {
            $this->recorder->record($this->aria, self::ILYRA_CORPORATION_ID, self::ILYRA_MOON_ID, $scan);
        } catch (ValidationException $refusal) {
            $paths = [];
            foreach ($refusal->getConstraintViolationList() as $violation) {
                $paths[] = $violation->getPropertyPath();
            }

            return $paths;
        }

        self::fail('The scan should have been refused');
    }

    private function storedCompositions(): array
    {
        $this->em->clear();

        return array_map(
            static fn (array $row): array => [(int) $row['corporation_id'], (int) $row['moon_id'], $row['recorded_by_id'], $row['recorded_at'], json_decode($row['lines'], true, flags: JSON_THROW_ON_ERROR)],
            $this->em->getConnection()->fetchAllAssociative('SELECT corporation_id, moon_id, recorded_by_id, recorded_at, lines FROM moon_compositions ORDER BY corporation_id, moon_id'),
        );
    }
}
