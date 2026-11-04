<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MoonChunk;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-308')]
final class MoonChunkValueApiTest extends WebTestCase
{
    use DrivesMoonChunkApi;

    private const string ENDPOINT = '/api/mining/corporation-extractions';

    protected function setUp(): void
    {
        $this->seedMoonChunkWorld();
    }

    public function testAChunkWithARecordedCompositionIsValuedAtTheJitaBuyCompressedEquivalentPricesAndSplitBetweenFourSixAndEightParticipants(): void
    {
        $this->as($this->aria);

        $this->request('GET', self::ENDPOINT);

        self::assertSame(
            [
                'known',
                null,
                1_000_000.0,
                14_375_000.0,
                [[4, 3_593_750.0], [6, 2_395_833.33], [8, 1_796_875.0]],
                [[self::BITUMENS, 'Bitumens', 0.5, 50_000.0, 5_000_000.0], [self::CHROMITE, 'Chromite', 0.5, 31_250.0, 9_375_000.0]],
                '09:30:00',
            ],
            $this->figuresOf($this->chunkValueOf(self::ILYRA_CORPORATION_ID, self::KNOWN_VALUE_MOON_ID)),
        );
    }

    public function testAnOreKnownAtAPriceOfZeroKeepsTheReadyChunkKnown(): void
    {
        $this->as($this->aria);

        $this->request('GET', self::ENDPOINT);

        self::assertSame(
            [
                'known',
                null,
                1_200_000.0,
                6_000_000.0,
                [[4, 1_500_000.0], [6, 1_000_000.0], [8, 750_000.0]],
                [[self::BITUMENS, 'Bitumens', 0.5, 60_000.0, 6_000_000.0], [self::ZEOLITES, 'Zeolites', 0.5, 120_000.0, 0.0]],
                '09:30:00',
            ],
            $this->figuresOf($this->chunkValueOf(self::ILYRA_CORPORATION_ID, self::ZERO_PRICE_MOON_ID)),
        );
    }

    public function testAMoonWithoutCompositionHasAnUnknownValueForLackOfScanButKeepsTheVolume(): void
    {
        $this->as($this->aria);

        $this->request('GET', self::ENDPOINT);

        self::assertSame(
            ['unknown', 'no_scan', 1_200_000.0, null, null, null, null],
            $this->figuresOf($this->chunkValueOf(self::ILYRA_CORPORATION_ID, self::NO_SCAN_MOON_ID)),
        );
    }

    public function testAnOreWithoutPriceMakesTheValueUnknownWithoutAPartialTotalAndKeepsTheScanDate(): void
    {
        $this->as($this->aria);

        $this->request('GET', self::ENDPOINT);

        self::assertSame(
            ['unknown', 'no_price', 1_800_000.0, null, null, null, '10:15:00'],
            $this->figuresOf($this->chunkValueOf(self::ILYRA_CORPORATION_ID, self::NO_PRICE_MOON_ID)),
        );
    }

    public function testAnExplodedChunkHasNoEstimatedValueEvenWithARecordedComposition(): void
    {
        $this->as($this->aria);

        $this->request('GET', self::ENDPOINT);

        $explodedChunk = $this->extractionOf(self::ILYRA_CORPORATION_ID, self::EXPLODED_MOON_ID);
        self::assertSame('exploded', $explodedChunk['status'] ?? null);
        self::assertArrayHasKey('chunkValue', $explodedChunk);
        self::assertNull($explodedChunk['chunkValue']);
    }

    public function testAUserOfAnotherCorporationSeesNeitherTheCompositionNorTheValueOfTheSameMoon(): void
    {
        $this->as($this->brann);

        $this->request('GET', self::ENDPOINT);

        $corporationIds = array_column($this->responseJson()['corporations'] ?? [], 'corporationId');
        self::assertSame(
            [[self::CYRA_CORPORATION_ID], ['unknown', 'no_scan', 1_000_000.0, null, null, null, null], false],
            [
                $corporationIds,
                $this->figuresOf($this->chunkValueOf(self::CYRA_CORPORATION_ID, self::KNOWN_VALUE_MOON_ID)),
                str_contains((string) $this->client->getResponse()->getContent(), 'Bitumens'),
            ],
        );
    }

    public function testTheOreUnitPricesOfAllTheChunksAreReadWithASingleMarketCall(): void
    {
        $this->as($this->aria);

        $this->request('GET', self::ENDPOINT);

        self::assertSame(1, $this->buyPriceCalls);
    }

    private function chunkValueOf(int $corporationId, int $moonId): array
    {
        $extraction = $this->extractionOf($corporationId, $moonId);
        self::assertArrayHasKey('chunkValue', $extraction);
        self::assertIsArray($extraction['chunkValue']);

        return $extraction['chunkValue'];
    }

    private function extractionOf(int $corporationId, int $moonId): array
    {
        $this->assertResponseIsSuccessful();
        foreach ($this->responseJson()['corporations'] ?? [] as $corporation) {
            if (($corporation['corporationId'] ?? null) !== $corporationId) {
                continue;
            }
            foreach ($corporation['extractions'] ?? [] as $extraction) {
                if (($extraction['moonId'] ?? null) === $moonId) {
                    return $extraction;
                }
            }
        }

        self::fail(sprintf('No extraction of moon %d for corporation %d in the response', $moonId, $corporationId));
    }

    private function figuresOf(array $chunkValue): array
    {
        self::assertEqualsCanonicalizing(['status', 'reason', 'volumeM3', 'totalValue', 'perParticipant', 'lines', 'scanRecordedAt'], array_keys($chunkValue));

        return [
            $chunkValue['status'],
            $chunkValue['reason'],
            round($chunkValue['volumeM3'], 2),
            $chunkValue['totalValue'] === null ? null : round($chunkValue['totalValue'], 2),
            $chunkValue['perParticipant'] === null ? null : array_map(static fn (array $part): array => [$part['participants'], round($part['value'], 2)], $chunkValue['perParticipant']),
            $chunkValue['lines'] === null ? null : array_map(static fn (array $line): array => [$line['typeId'], $line['typeName'], $line['share'], round($line['units'], 2), round($line['value'], 2)], $chunkValue['lines']),
            $chunkValue['scanRecordedAt'] === null ? null : (new \DateTimeImmutable($chunkValue['scanRecordedAt']))->setTimezone(new \DateTimeZone('UTC'))->format('H:i:s'),
        ];
    }
}
