<?php

declare(strict_types=1);

namespace App\Tests\Integration\MiningSite;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\MiningSite;
use App\Entity\User;
use App\Enum\MiningSiteStatus;
use App\Service\MiningSite\MiningSiteScanRecorder;
use App\Service\MiningSite\ScanLine;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[CoversClass(MiningSiteScanRecorder::class)]
#[Group('issue-267')]
final class MiningSiteScanRecorderTest extends IntegrationTestCase
{
    use ClockSensitiveTrait;
    use SeedsMiningSiteMarket;

    private const string FIRST_SCAN_AT = '2026-11-05T20:00:00+00:00';

    private MiningSiteScanRecorder $recorder;
    private MiningSite $site;
    private User $owner;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::FIRST_SCAN_AT));
        parent::setUp();
        $this->replaceJitaBuyMarket();
        $this->seedMiningSiteSde($this->em);
        $this->owner = $this->createUser();
        $this->site = (new MiningSite())->setOwner($this->owner)->setLabel('Belt Aldranette')->setSolarSystemId(self::ALDRANETTE);
        $this->em->persist($this->site);
        $this->em->flush();
        $this->recorder = self::getContainer()->get(MiningSiteScanRecorder::class);
    }

    public function testFirstScanStoresUnitsVolumePriceAndValueOfEachTypeWithUnknownEtasForASingleScan(): void
    {
        $this->recordAt('20:00:00', [self::VELDSPAR => 30_000, self::SCORDITE => 8_000]);

        self::assertSame(
            [
                [
                    'at' => '20:00:00',
                    'totalUnits' => 38_000,
                    'totalVolumeM3' => 4_200.0,
                    'remainingValue' => 650_000.0,
                    'missingPriceTypeIds' => [],
                    'siteEtas' => [null, null],
                    'types' => [
                        self::VELDSPAR => ['present', 30_000, 3_000.0, 15.0, 450_000.0, 'single_scan'],
                        self::SCORDITE => ['present', 8_000, 1_200.0, 25.0, 200_000.0, 'single_scan'],
                    ],
                ],
            ],
            array_map(fn (array $scan): array => $this->figuresOf($scan), $this->storedScans()),
        );
    }

    public function testEachScanKeepsTheRatesAndEtasAnnouncedWhenItWasRecorded(): void
    {
        $this->recordAt('20:00:00', [self::VELDSPAR => 30_000]);
        $this->recordAt('20:10:00', [self::VELDSPAR => 25_000]);
        $this->recordAt('20:20:00', [self::VELDSPAR => 18_000]);

        self::assertSame(
            [
                [[null, null, null, null], [null, null], ['single_scan'], [null, null]],
                [[500.0, '21:00:00', 500.0, '21:00:00'], [null, null], [null], ['21:00:00', '21:00:00']],
                [[700.0, '20:45:43', 600.0, '20:50:33'], [666.67, 2.22], [null], ['20:45:43', '20:50:33']],
            ],
            array_map(
                fn (array $scan): array => [
                    $this->ratesAndEtasOf($scan['types'][self::VELDSPAR]),
                    [$this->rounded($scan['types'][self::VELDSPAR]['maxDeviationUnits'] ?? null), $this->rounded($scan['types'][self::VELDSPAR]['maxDeviationPercent'] ?? null)],
                    [$scan['types'][self::VELDSPAR]['etaUnknownReason'] ?? null],
                    $this->figuresOf($scan)['siteEtas'],
                ],
                $this->storedScans(),
            ),
        );
    }

    public function testPriceReadAtEachScanIsKeptWhenTheJitaPriceChangesBetweenScans(): void
    {
        $this->recordAt('20:00:00', [self::VELDSPAR => 30_000]);
        $this->jitaBuyPrices[self::COMPRESSED_VELDSPAR] = 16.0;
        $this->recordAt('20:10:00', [self::VELDSPAR => 25_000]);

        self::assertSame(
            [
                [15.0, 450_000.0, 450_000.0],
                [16.0, 400_000.0, 400_000.0],
            ],
            array_map(
                fn (array $scan): array => [$this->figuresOf($scan)['types'][self::VELDSPAR][3], $this->figuresOf($scan)['types'][self::VELDSPAR][4], $this->figuresOf($scan)['remainingValue']],
                $this->storedScans(),
            ),
        );
    }

    public function testScanIsDatedWithTheServerTimeTruncatedToTheSecond(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:00:00.789123+00:00'));

        $this->record([self::VELDSPAR => 30_000]);

        self::assertSame(['20:00:00'], array_map(fn (array $scan): string => $this->figuresOf($scan)['at'], $this->storedScans()));
    }

    public function testSecondScanInTheSameSecondIsRefusedWithAValidationErrorAndNothingIsWritten(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:00:00.100000+00:00'));
        $this->record([self::VELDSPAR => 60_000]);
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:00:00.900000+00:00'));

        $refusal = $this->refusalOf([self::VELDSPAR => 42_000]);

        self::assertSame(
            [ValidationException::class, [[60_000]], MiningSiteStatus::Active->value],
            [$refusal, array_map(fn (array $scan): array => [$this->figuresOf($scan)['types'][self::VELDSPAR][1]], $this->storedScans()), $this->storedStatus()],
        );
    }

    public function testLastTypeDepletedClosesTheSiteAndTheNextScanIsRefusedAsBadRequest(): void
    {
        $this->recordAt('20:00:00', [self::SCORDITE => 2_000]);
        $this->recordAt('20:30:00', []);
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:40:00+00:00'));

        $refusal = $this->refusalOf([self::SCORDITE => 1_500]);

        self::assertSame(
            [
                MiningSiteStatus::Closed->value,
                BadRequestHttpException::class,
                [
                    [2_000, 50_000.0, [null, null], ['present', 2_000, [null, null]]],
                    [0, 0.0, [null, null], ['depleted', 0, ['20:00:00', '20:30:00']]],
                ],
            ],
            [
                $this->storedStatus(),
                $refusal,
                array_map(
                    fn (array $scan): array => [
                        $this->figuresOf($scan)['totalUnits'],
                        $this->figuresOf($scan)['remainingValue'],
                        $this->figuresOf($scan)['siteEtas'],
                        [$scan['types'][self::SCORDITE]['status'] ?? null, $scan['types'][self::SCORDITE]['units'] ?? null, $this->depletedBetweenOf($scan['types'][self::SCORDITE])],
                    ],
                    $this->storedScans(),
                ),
            ],
        );
    }

    public function testSiteClosedByHandRefusesTheScanAsBadRequest(): void
    {
        $this->site->setStatus(MiningSiteStatus::Closed);
        $this->em->flush();

        $refusal = $this->refusalOf([self::VELDSPAR => 30_000]);

        self::assertSame([BadRequestHttpException::class, 0], [$refusal, count($this->storedScans())]);
    }

    public function testTypeUnknownToTheSdeRefusesTheWholeScanWithAValidationError(): void
    {
        $refusal = $this->refusalOf([self::VELDSPAR => 30_000, self::UNKNOWN_TYPE => 500]);

        self::assertSame([ValidationException::class, 0], [$refusal, count($this->storedScans())]);
    }

    public function testFirstScanWithoutAnyTypeIsRefusedWithAValidationError(): void
    {
        $refusal = $this->refusalOf([]);

        self::assertSame([ValidationException::class, 0], [$refusal, count($this->storedScans())]);
    }

    public function testTypeWithoutJitaPriceHasAnUnknownValueAndIsListedAsMissingPrice(): void
    {
        unset($this->jitaBuyPrices[self::COMPRESSED_SCORDITE]);

        $this->recordAt('20:00:00', [self::VELDSPAR => 30_000, self::SCORDITE => 8_000]);

        self::assertSame(
            [[null, [self::SCORDITE], [15.0, 450_000.0], [null, null, 1_200.0]]],
            array_map(
                fn (array $scan): array => [
                    $this->figuresOf($scan)['remainingValue'],
                    $this->figuresOf($scan)['missingPriceTypeIds'],
                    [$this->figuresOf($scan)['types'][self::VELDSPAR][3], $this->figuresOf($scan)['types'][self::VELDSPAR][4]],
                    [$this->figuresOf($scan)['types'][self::SCORDITE][3], $this->figuresOf($scan)['types'][self::SCORDITE][4], $this->figuresOf($scan)['types'][self::SCORDITE][2]],
                ],
                $this->storedScans(),
            ),
        );
    }

    private function recordAt(string $time, array $unitsByTypeId): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-05T'.$time.'+00:00'));
        $this->record($unitsByTypeId);
    }

    private function record(array $unitsByTypeId): void
    {
        $this->recorder->record(
            $this->site,
            array_map(static fn (int $typeId, int $units): ScanLine => new ScanLine($typeId, $units), array_keys($unitsByTypeId), $unitsByTypeId),
            $this->owner,
        );
    }

    private function refusalOf(array $unitsByTypeId): ?string
    {
        try {
            $this->record($unitsByTypeId);
        } catch (ValidationException|BadRequestHttpException $refusal) {
            return $refusal::class;
        }

        return null;
    }

    private function storedStatus(): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM mining_sites WHERE id = ?', [(string) $this->site->getId()]);
    }

    private function storedScans(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT scanned_at, total_units, total_volume_m3, remaining_value, missing_price_type_ids, recent_eta, trend_eta, types FROM mining_site_scans WHERE site_id = ? ORDER BY scanned_at',
            [(string) $this->site->getId()],
        );

        return array_map(static fn (array $row): array => [
            'scanned_at' => $row['scanned_at'],
            'total_units' => $row['total_units'],
            'total_volume_m3' => $row['total_volume_m3'],
            'remaining_value' => $row['remaining_value'],
            'missing_price_type_ids' => json_decode((string) $row['missing_price_type_ids'], true, flags: JSON_THROW_ON_ERROR),
            'recent_eta' => $row['recent_eta'],
            'trend_eta' => $row['trend_eta'],
            'types' => array_column(json_decode((string) $row['types'], true, flags: JSON_THROW_ON_ERROR), null, 'typeId'),
        ], $rows);
    }

    private function figuresOf(array $scan): array
    {
        return [
            'at' => $this->timeOfDay($scan['scanned_at']),
            'totalUnits' => (int) $scan['total_units'],
            'totalVolumeM3' => $this->rounded($scan['total_volume_m3']),
            'remainingValue' => $this->rounded($scan['remaining_value']),
            'missingPriceTypeIds' => $scan['missing_price_type_ids'],
            'siteEtas' => [$this->timeOfDay($scan['recent_eta']), $this->timeOfDay($scan['trend_eta'])],
            'types' => array_map(fn (array $entry): array => [
                $entry['status'] ?? null,
                $entry['units'] ?? null,
                $this->rounded($entry['volumeM3'] ?? null),
                $this->rounded($entry['unitPrice'] ?? null),
                $this->rounded($entry['remainingValue'] ?? null),
                $entry['etaUnknownReason'] ?? null,
            ], $scan['types']),
        ];
    }

    private function ratesAndEtasOf(array $entry): array
    {
        return [
            $this->rounded($entry['recentRate'] ?? null),
            $this->timeOfDay($entry['recentEta'] ?? null),
            $this->rounded($entry['trendRate'] ?? null),
            $this->timeOfDay($entry['trendEta'] ?? null),
        ];
    }

    private function depletedBetweenOf(array $entry): array
    {
        return [$this->timeOfDay($entry['depletedBetween']['from'] ?? null), $this->timeOfDay($entry['depletedBetween']['to'] ?? null)];
    }

    private function rounded(int|float|string|null $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }

    private function timeOfDay(?string $instant): ?string
    {
        return $instant === null ? null : (new \DateTimeImmutable($instant, new \DateTimeZone('UTC')))->format('H:i:s');
    }
}
