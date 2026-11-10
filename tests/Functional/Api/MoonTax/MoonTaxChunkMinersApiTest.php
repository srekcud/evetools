<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MoonTax;

use App\Entity\CorporationMiningExtraction;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-369')]
final class MoonTaxChunkMinersApiTest extends MoonTaxApiTestCase
{
    private const string CHUNK_ARRIVAL = '2026-11-05T20:00:00+00:00';
    private const string CHUNK_DECAY = '2026-11-08T23:00:00+00:00';
    private const string FIRST_WEEK_START = '2026-10-26T00:00:00+00:00';
    private const string SECOND_WEEK_START = '2026-11-02T00:00:00+00:00';
    private const string THIRD_WEEK_START = '2026-11-09T00:00:00+00:00';
    private const string FOURTH_WEEK_START = '2026-11-16T00:00:00+00:00';
    private const string UNKNOWN_CHUNK_ID = '0195f3a0-2740-7000-8000-00000000ffff';
    private const string NOT_ARRIVED_MESSAGE = 'This moon chunk has not arrived yet';
    private const array MINER_FIELDS = ['characterId', 'characterName', 'chunkTax', 'unknownTypeIds', 'statements'];
    private const array STATEMENT_FIELDS = ['periodStart', 'periodEnd', 'reference', 'status', 'tax', 'paid', 'remaining'];

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jitaBuyPrices[self::COMPRESSED_CINNABAR] = 50.0;
        $this->manager = $this->accountant();
        $this->persistSetting(self::ROCK_HOLLOW_ID);
    }

    public function testMinerCarriesTheTaxOfHisRecordsOfTheChunkAtCurrentPricesAndTheStatementOfItsWeek(): void
    {
        $chunk = $this->persistChunk(self::CHUNK_ARRIVAL, self::CHUNK_DECAY);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-06', 100);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-04', 4_000);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-09', 7_000);
        $this->persistObserverRecord(self::DEEP_CORE_ID, self::MIRA_ID, self::CINNABAR, '2026-11-06', 9_000);
        $this->persistStatement('TAX-2026-W45-AAAA', self::DAX_ID, '2026-11-02', 20_500.0);

        $this->request($this->manager, 'GET', $this->minersPath($chunk));

        $json = $this->responseJson();
        self::assertSame(
            [
                200,
                $chunk->getId()?->toRfc4122(),
                'current_prices',
                true,
                [[self::DAX_ID, 'Dax Orlen', 500.0, [], [[self::SECOND_WEEK_START, self::THIRD_WEEK_START, 'TAX-2026-W45-AAAA', 'due', 20_500.0, 0.0, 20_500.0]]]],
            ],
            [$this->statusCode(), $json['id'] ?? null, $json['taxBasis'] ?? null, $json['refineryOpen'] ?? null, $this->minerRows()],
        );
    }

    public function testMinersAreSortedByDecreasingChunkTaxThenNameWithTheUnknownTaxLast(): void
    {
        $chunk = $this->persistChunk(self::CHUNK_ARRIVAL, self::CHUNK_DECAY);
        $this->respondWithCharacterNames([self::DAX_ID => 'Dax Orlen', self::MIRA_ID => 'Mira Venn', self::TOLA_ID => 'Tola Rask']);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::AUREL_ID, self::XENOTIME, '2026-11-06', 300);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::TOLA_ID, self::CINNABAR, '2026-11-06', 100);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::MIRA_ID, self::EUXENITE, '2026-11-07', 2_000);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-06', 100);

        $this->request($this->manager, 'GET', $this->minersPath($chunk));

        $miners = array_map($this->minerFieldsOf(...), $this->miners());
        self::assertSame(
            [
                200,
                [self::MIRA_ID, self::DAX_ID, self::TOLA_ID, self::AUREL_ID],
                ['Mira Venn', 'Dax Orlen', 'Tola Rask', null],
                [128_000.0, 500.0, 500.0, null],
            ],
            [$this->statusCode(), array_column($miners, 0), array_column($miners, 1), array_column($miners, 2)],
        );
    }

    public function testMinerWithATypeWithoutPriceHasNoChunkTaxAndItsUnknownTypesListedNotAPartialSum(): void
    {
        $chunk = $this->persistChunk(self::CHUNK_ARRIVAL, self::CHUNK_DECAY);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::TOLA_ID, self::ZEOLITES, '2026-11-06', 1_000);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::TOLA_ID, self::XENOTIME, '2026-11-07', 300);

        $this->request($this->manager, 'GET', $this->minersPath($chunk));

        $miners = array_map($this->minerFieldsOf(...), $this->miners());
        self::assertSame([200, [[self::TOLA_ID, null, null, [self::XENOTIME]]]], [$this->statusCode(), array_map(static fn (array $miner): array => array_slice($miner, 0, 4), $miners)]);
    }

    public function testChunkOverTwoPeriodsGivesOneStatementPerPeriodTheOldestFirst(): void
    {
        $chunk = $this->persistChunk('2026-10-29T20:00:00+00:00', '2026-11-02T23:00:00+00:00', '2026-10-26T18:00:00+00:00');
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-01', 100);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-02', 50);
        $this->persistStatement('TAX-2026-W44-AAAA', self::DAX_ID, '2026-10-26', 6_000_000.0);

        $this->request($this->manager, 'GET', $this->minersPath($chunk));

        $miners = array_map($this->minerFieldsOf(...), $this->miners());
        self::assertSame(
            [
                200,
                750.0,
                [
                    [self::FIRST_WEEK_START, self::SECOND_WEEK_START, 'TAX-2026-W44-AAAA', 'late', 6_000_000.0, 0.0, 6_000_000.0],
                    [self::SECOND_WEEK_START, self::THIRD_WEEK_START, null, null, null, null, null],
                ],
            ],
            [$this->statusCode(), $miners[0][2] ?? null, array_map($this->statementFieldsOf(...), $miners[0][4] ?? [])],
        );
    }

    public function testStatementPartiallyPaidKeepsTheFigureOfTheWholePeriod(): void
    {
        $chunk = $this->persistChunk(self::CHUNK_ARRIVAL, self::CHUNK_DECAY);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-06', 100);
        $this->persistStatement('TAX-2026-W45-AAAA', self::DAX_ID, '2026-11-02', 500.0);
        $this->persistDonation(7_001, self::DAX_ID, 300.0, '2026-11-03 10:00:00');

        $this->request($this->manager, 'GET', $this->minersPath($chunk));

        $miners = array_map($this->minerFieldsOf(...), $this->miners());
        self::assertSame(
            [200, [[self::SECOND_WEEK_START, self::THIRD_WEEK_START, 'TAX-2026-W45-AAAA', 'partial', 500.0, 300.0, 200.0]]],
            [$this->statusCode(), array_map($this->statementFieldsOf(...), $miners[0][4] ?? [])],
        );
    }

    public function testPeriodWithoutStatementIsListedWithAnUnknownStatusAndNoAmount(): void
    {
        $chunk = $this->persistChunk(self::CHUNK_ARRIVAL, self::CHUNK_DECAY);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-06', 100);

        $this->request($this->manager, 'GET', $this->minersPath($chunk));

        $miners = array_map($this->minerFieldsOf(...), $this->miners());
        self::assertSame(
            [200, 500.0, [[self::SECOND_WEEK_START, self::THIRD_WEEK_START, null, null, null, null, null]]],
            [$this->statusCode(), $miners[0][2] ?? null, array_map($this->statementFieldsOf(...), $miners[0][4] ?? [])],
        );
    }

    public function testReadyChunkOfThePeriodInProgressCarriesTheProvisionalStatement(): void
    {
        $chunk = $this->persistChunk('2026-11-09T20:00:00+00:00', '2026-11-12T23:00:00+00:00', '2026-11-06T18:00:00+00:00');
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-10', 100);

        $this->request($this->manager, 'GET', $this->minersPath($chunk));

        $miners = array_map($this->minerFieldsOf(...), $this->miners());
        self::assertSame(
            [200, 500.0, [[self::THIRD_WEEK_START, self::FOURTH_WEEK_START, null, 'provisional', 500.0, 0.0, 500.0]]],
            [$this->statusCode(), $miners[0][2] ?? null, array_map($this->statementFieldsOf(...), $miners[0][4] ?? [])],
        );
    }

    public function testRecordOfADayCoveredByTwoChunksIsTaxedForTheChunkArrivedLastOnly(): void
    {
        $earlier = $this->persistChunk(self::CHUNK_ARRIVAL, self::CHUNK_DECAY);
        $later = $this->persistChunk('2026-11-08T20:00:00+00:00', '2026-11-11T23:00:00+00:00', '2026-11-05T18:00:00+00:00');
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-06', 100);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::MIRA_ID, self::EUXENITE, '2026-11-08', 2_000);

        $this->request($this->manager, 'GET', $this->minersPath($earlier));
        $earlierMiners = array_map($this->minerFieldsOf(...), $this->miners());
        $this->request($this->manager, 'GET', $this->minersPath($later));
        $laterMiners = array_map($this->minerFieldsOf(...), $this->miners());

        self::assertSame(
            [[self::DAX_ID, 500.0], [self::MIRA_ID, 128_000.0]],
            [[$earlierMiners[0][0] ?? null, $earlierMiners[0][2] ?? null], [$laterMiners[0][0] ?? null, $laterMiners[0][2] ?? null]],
        );
        self::assertSame([1, 1], [count($earlierMiners), count($laterMiners)]);
    }

    public function testOpenFlagTellsARefineryWithoutSettingFromAnOpenOneWithNoRecordInTheChunk(): void
    {
        $openChunk = $this->persistChunk(self::CHUNK_ARRIVAL, self::CHUNK_DECAY);
        $closedChunk = $this->persistChunk(self::CHUNK_ARRIVAL, self::CHUNK_DECAY, structureId: self::DEEP_CORE_ID);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-04', 100);
        $this->persistObserverRecord(self::DEEP_CORE_ID, self::DAX_ID, self::CINNABAR, '2026-11-06', 100);

        $this->request($this->manager, 'GET', $this->minersPath($openChunk));
        $open = [$this->statusCode(), $this->responseJson()];
        $this->request($this->manager, 'GET', $this->minersPath($closedChunk));
        $closed = [$this->statusCode(), $this->responseJson()];

        foreach ([$open, $closed] as [, $json]) {
            self::assertArrayHasKey('refineryOpen', $json);
            self::assertArrayHasKey('miners', $json);
        }
        self::assertSame(
            [[200, true, []], [200, false, []]],
            [[$open[0], $open[1]['refineryOpen'], $open[1]['miners']], [$closed[0], $closed[1]['refineryOpen'], $closed[1]['miners']]],
        );
    }

    public function testUserWithoutTheAccountantRoleIsRefusedAndSeesNoTax(): void
    {
        $chunk = $this->persistChunk(self::CHUNK_ARRIVAL, self::CHUNK_DECAY);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-06', 100);
        $this->persistCharacterOfCorporation('Brann Kesh', self::BRANN_ID, self::CORPORATION, ['Director']);
        $this->em->flush();

        $this->request($this->usersByEveCharacterId[self::BRANN_ID], 'GET', $this->minersPath($chunk));

        self::assertSame([403, false, false], [$this->statusCode(), str_contains($this->content(), 'Dax Orlen'), str_contains($this->content(), 'chunkTax')]);
    }

    public function testUnreadableRolesAreAServiceUnavailableErrorNotAGrantedAccess(): void
    {
        $chunk = $this->persistChunk(self::CHUNK_ARRIVAL, self::CHUNK_DECAY);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-06', 100);
        $this->em->flush();
        $this->failEsi(self::rolesEndpoint(self::ILYRA_ID), 500);

        $this->request($this->manager, 'GET', $this->minersPath($chunk));

        self::assertSame([503, false], [$this->statusCode(), str_contains($this->content(), 'Dax Orlen')]);
    }

    public function testUnknownChunkIsNotFound(): void
    {
        $this->request($this->manager, 'GET', $this->minersPathOf(self::UNKNOWN_CHUNK_ID));

        self::assertSame(404, $this->statusCode());
    }

    public function testMalformedChunkIdIsNotFound(): void
    {
        $this->request($this->manager, 'GET', $this->minersPathOf('not-a-chunk'));

        self::assertSame(404, $this->statusCode());
    }

    public function testChunkOfAnotherCorporationIsNotFoundForTheManagerOfThisOne(): void
    {
        $chunk = $this->persistChunk(self::CHUNK_ARRIVAL, self::CHUNK_DECAY, corporationId: self::OTHER_OWNER_CORPORATION);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-06', 100, self::OTHER_OWNER_CORPORATION, self::OTHER_OWNER_CORPORATION);

        $this->request($this->manager, 'GET', $this->minersPath($chunk));

        self::assertSame([404, false], [$this->statusCode(), str_contains($this->content(), 'Dax Orlen')]);
    }

    public function testChunkStillExtractingIsARejectedRequest(): void
    {
        $chunk = $this->persistChunk('2026-11-12T20:00:00+00:00', '2026-11-15T23:00:00+00:00', '2026-11-09T18:00:00+00:00');
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-13', 100);

        $this->request($this->manager, 'GET', $this->minersPath($chunk));

        self::assertSame([400, true, false], [$this->statusCode(), str_contains($this->content(), self::NOT_ARRIVED_MESSAGE), str_contains($this->content(), 'Dax Orlen')]);
    }

    private function persistChunk(string $arrival, string $naturalDecay, string $extractionStart = '2026-11-02T18:00:00+00:00', int $structureId = self::ROCK_HOLLOW_ID, int $corporationId = self::CORPORATION): CorporationMiningExtraction
    {
        $chunk = $this->persistExtraction($structureId, $corporationId)
            ->setExtractionStartTime(new \DateTimeImmutable($extractionStart))
            ->setChunkArrivalTime(new \DateTimeImmutable($arrival))
            ->setNaturalDecayTime(new \DateTimeImmutable($naturalDecay));
        $this->em->flush();

        return $chunk;
    }

    private function minersPath(CorporationMiningExtraction $chunk): string
    {
        return $this->minersPathOf((string) $chunk->getId()?->toRfc4122());
    }

    private function minersPathOf(string $chunkId): string
    {
        return self::BASE_PATH . '/' . self::CORPORATION . '/chunks/' . $chunkId . '/miners';
    }

    private function miners(): array
    {
        $json = $this->responseJson();
        self::assertArrayHasKey('miners', $json);
        self::assertIsArray($json['miners']);

        return $json['miners'];
    }

    private function minerRows(): array
    {
        return array_map(function (array $miner): array {
            $fields = $this->minerFieldsOf($miner);
            $fields[4] = array_map($this->statementFieldsOf(...), $fields[4]);

            return $fields;
        }, $this->miners());
    }

    private function minerFieldsOf(array $miner): array
    {
        $fields = [];
        foreach (self::MINER_FIELDS as $field) {
            self::assertArrayHasKey($field, $miner);
            $fields[] = $miner[$field];
        }

        return $fields;
    }

    private function statementFieldsOf(array $statement): array
    {
        $fields = [];
        foreach (self::STATEMENT_FIELDS as $field) {
            self::assertArrayHasKey($field, $statement);
            $fields[] = $statement[$field];
        }
        $fields[0] = $this->utc(new \DateTimeImmutable($fields[0]));
        $fields[1] = $this->utc(new \DateTimeImmutable($fields[1]));

        return $fields;
    }
}
