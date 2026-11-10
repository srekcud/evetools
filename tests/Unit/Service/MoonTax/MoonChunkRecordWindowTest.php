<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MoonTax;

use App\Entity\CorporationMiningExtraction;
use App\Entity\MoonObserverRecord;
use App\Service\MoonTax\MoonChunkRecordWindow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoonChunkRecordWindow::class)]
#[Group('issue-369')]
final class MoonChunkRecordWindowTest extends TestCase
{
    private const int ROCK_HOLLOW_ID = 1_035_000_000_101;
    private const int DAX_ID = 2_112_000_101;
    private const int MIRA_ID = 2_112_000_102;
    private const int CINNABAR = 45_510;
    private const int EUXENITE = 45_495;
    private const int ZEOLITES = 45_490;

    private MoonChunkRecordWindow $window;

    protected function setUp(): void
    {
        $this->window = new MoonChunkRecordWindow();
    }

    public function testRecordsOfTheDayBeforeTheArrivalAndOfTheDayAfterTheNaturalDecayBelongToNoChunk(): void
    {
        $chunk = $this->chunk('2026-11-05T20:00:00+00:00', '2026-11-08T23:00:00+00:00', '2026-11-02T18:00:00+00:00');
        $records = [
            $this->record('2026-11-04', self::DAX_ID, self::CINNABAR, 4_000),
            $this->record('2026-11-06', self::DAX_ID, self::CINNABAR, 100),
            $this->record('2026-11-09', self::MIRA_ID, self::EUXENITE, 2_000),
        ];

        self::assertSame(['2026-11-06'], $this->daysOf($this->window->recordsOf($chunk, [$chunk], $records)));
    }

    public function testRecordsOfTheArrivalDayAndOfTheNaturalDecayDayAreBothCounted(): void
    {
        $chunk = $this->chunk('2026-11-05T20:00:00+00:00', '2026-11-08T23:00:00+00:00', '2026-11-02T18:00:00+00:00');
        $records = [
            $this->record('2026-11-04', self::DAX_ID, self::CINNABAR, 4_000),
            $this->record('2026-11-05', self::DAX_ID, self::CINNABAR, 150),
            $this->record('2026-11-08', self::MIRA_ID, self::EUXENITE, 2_000),
            $this->record('2026-11-09', self::MIRA_ID, self::ZEOLITES, 30_000),
        ];

        self::assertSame(['2026-11-05', '2026-11-08'], $this->daysOf($this->window->recordsOf($chunk, [$chunk], $records)));
    }

    public function testRecordOfADayCoveredByTwoChunksOfTheRefineryGoesToTheChunkArrivedLastOnly(): void
    {
        $earlier = $this->chunk('2026-11-05T20:00:00+00:00', '2026-11-08T23:00:00+00:00', '2026-11-02T18:00:00+00:00');
        $later = $this->chunk('2026-11-08T20:00:00+00:00', '2026-11-11T23:00:00+00:00', '2026-11-05T18:00:00+00:00');
        $records = [
            $this->record('2026-11-06', self::DAX_ID, self::CINNABAR, 100),
            $this->record('2026-11-08', self::MIRA_ID, self::EUXENITE, 2_000),
            $this->record('2026-11-09', self::DAX_ID, self::ZEOLITES, 30_000),
        ];

        self::assertSame(
            [['2026-11-06'], ['2026-11-08', '2026-11-09']],
            [
                $this->daysOf($this->window->recordsOf($earlier, [$earlier, $later], $records)),
                $this->daysOf($this->window->recordsOf($later, [$earlier, $later], $records)),
            ],
        );
    }

    public function testChunkOrderOfTheRefineryDoesNotChangeTheOwnerOfARecord(): void
    {
        $earlier = $this->chunk('2026-11-05T20:00:00+00:00', '2026-11-08T23:00:00+00:00', '2026-11-02T18:00:00+00:00');
        $later = $this->chunk('2026-11-08T20:00:00+00:00', '2026-11-11T23:00:00+00:00', '2026-11-05T18:00:00+00:00');
        $records = [$this->record('2026-11-08', self::MIRA_ID, self::EUXENITE, 2_000)];

        self::assertSame(
            [[], ['2026-11-08']],
            [
                $this->daysOf($this->window->recordsOf($earlier, [$later, $earlier], $records)),
                $this->daysOf($this->window->recordsOf($later, [$later, $earlier], $records)),
            ],
        );
    }

    public function testArrivalTimeIsReadAsAUtcDayWhateverItsOffset(): void
    {
        $chunk = $this->chunk('2026-11-06T01:30:00+02:00', '2026-11-09T01:30:00+02:00', '2026-11-03T01:30:00+02:00');
        $records = [
            $this->record('2026-11-05', self::DAX_ID, self::CINNABAR, 100),
            $this->record('2026-11-08', self::MIRA_ID, self::EUXENITE, 2_000),
            $this->record('2026-11-09', self::DAX_ID, self::ZEOLITES, 30_000),
        ];

        self::assertSame(['2026-11-05', '2026-11-08'], $this->daysOf($this->window->recordsOf($chunk, [$chunk], $records)));
    }

    private function chunk(string $arrival, string $naturalDecay, string $extractionStart): CorporationMiningExtraction
    {
        return (new CorporationMiningExtraction())
            ->setCorporationId(98_000_001)
            ->setStructureId(self::ROCK_HOLLOW_ID)
            ->setMoonId(40_301_001)
            ->setExtractionStartTime(new \DateTimeImmutable($extractionStart))
            ->setChunkArrivalTime(new \DateTimeImmutable($arrival))
            ->setNaturalDecayTime(new \DateTimeImmutable($naturalDecay));
    }

    private function record(string $lastUpdated, int $characterId, int $typeId, int $quantity): MoonObserverRecord
    {
        return (new MoonObserverRecord())
            ->setCorporationId(98_000_001)
            ->setObserverId(self::ROCK_HOLLOW_ID)
            ->setCharacterId($characterId)
            ->setTypeId($typeId)
            ->setLastUpdated(new \DateTimeImmutable($lastUpdated, new \DateTimeZone('UTC')))
            ->setQuantity($quantity)
            ->setRecordedCorporationId(98_000_001);
    }

    /**
     * @param list<MoonObserverRecord> $records
     *
     * @return list<string>
     */
    private function daysOf(array $records): array
    {
        $days = array_map(static fn (MoonObserverRecord $record): string => $record->getLastUpdated()->format('Y-m-d'), $records);
        sort($days);

        return $days;
    }
}
