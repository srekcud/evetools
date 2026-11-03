<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-311')]
final class MiningOperationBoostShipApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use DrivesTheE1MiningOperation;
    use SeedsBoostShipSde;

    private const string NOT_ADMIN_REFUSAL = 'Only the operation owner or an admin can perform this action';

    protected function setUp(): void
    {
        $this->setUpTheE1Pilots();
        $this->seedBoostShipSde($this->em);
        $this->em->flush();
    }

    public function testCatalogueListsTheThreeBoostShipsWithTheCompressorsOfTheSde(): void
    {
        $this->as($this->aria);

        $this->request('GET', '/api/mining-boost-ships');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                [
                    'typeId' => self::PORPOISE,
                    'name' => 'Porpoise',
                    'compressors' => [
                        ['kind' => 'asteroid', 'typeId' => 62622, 'name' => 'Medium Asteroid Ore Compressor I'],
                        ['kind' => 'gas', 'typeId' => 62624, 'name' => 'Medium Gas Compressor I'],
                    ],
                ],
                [
                    'typeId' => self::ORCA,
                    'name' => 'Orca',
                    'compressors' => [
                        ['kind' => 'asteroid', 'typeId' => 62625, 'name' => 'Large Asteroid Ore Compressor I'],
                        ['kind' => 'gas', 'typeId' => 62626, 'name' => 'Large Gas Compressor I'],
                        ['kind' => 'ice', 'typeId' => 62628, 'name' => 'Large Ice Compressor I'],
                        ['kind' => 'mercoxit', 'typeId' => 62630, 'name' => 'Large Mercoxit Compressor I'],
                        ['kind' => 'moon', 'typeId' => 62631, 'name' => 'Large Moon Ore Compressor I'],
                    ],
                ],
                [
                    'typeId' => self::RORQUAL,
                    'name' => 'Rorqual',
                    'compressors' => [
                        ['kind' => 'asteroid', 'typeId' => 62632, 'name' => 'Capital Asteroid Ore Compressor I'],
                        ['kind' => 'gas', 'typeId' => 62634, 'name' => 'Capital Gas Compressor I'],
                        ['kind' => 'ice', 'typeId' => 62633, 'name' => 'Capital Ice Compressor I'],
                        ['kind' => 'mercoxit', 'typeId' => 62635, 'name' => 'Capital Mercoxit Compressor I'],
                        ['kind' => 'moon', 'typeId' => 62636, 'name' => 'Capital Moon Ore Compressor I'],
                    ],
                ],
            ],
            $this->responseJson(),
        );
    }

    public function testOperationCreatedWithAnOrcaAndThreeMountedCompressorsReturnsThemInItsValuationSettings(): void
    {
        $this->as($this->elia);

        $this->request('POST', '/api/mining-operations', $this->creation(['boostShipTypeId' => self::ORCA, 'mountedCompressorKinds' => ['asteroid', 'gas', 'ice']]));

        self::assertResponseStatusCodeSame(201);
        $created = $this->responseJson();
        self::assertSame([self::ORCA, ['asteroid', 'gas', 'ice']], $this->boostOf($created['valuationSettings'] ?? []));

        $this->as($this->elia);
        $this->request('GET', '/api/mining-operations/' . $created['id']);
        self::assertSame([self::ORCA, ['asteroid', 'gas', 'ice']], $this->boostOf($this->responseJson()['valuationSettings'] ?? []));
    }

    public function testOperationCreatedWithoutBoostShipHasNoBoostShipAndNoMountedCompressor(): void
    {
        $this->as($this->elia);

        $this->request('POST', '/api/mining-operations', $this->creation());

        self::assertResponseStatusCodeSame(201);
        self::assertSame([null, []], $this->boostOf($this->responseJson()['valuationSettings'] ?? []));
    }

    public function testCreationWithACompressorThatCannotBeMountedOnTheHullIsRefused(): void
    {
        $this->as($this->elia);

        $this->request('POST', '/api/mining-operations', $this->creation(['boostShipTypeId' => self::PORPOISE, 'mountedCompressorKinds' => ['moon']]));

        self::assertSame([400, 'The moon compressor cannot be mounted on a Porpoise'], $this->errorDetail());
    }

    public function testOwnerSetsAnOrcaWithItsCompressorsOnThePlannedOperation(): void
    {
        $this->as($this->aria);

        $this->request('PATCH', $this->valuationUri(), ['boostShipTypeId' => self::ORCA, 'mountedCompressorKinds' => ['asteroid', 'ice']]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::ORCA, ['asteroid', 'ice']], $this->boostOf($this->responseJson()['settings'] ?? []));
    }

    public function testIceCompressorCannotBeMountedOnThePorpoise(): void
    {
        $this->as($this->aria);

        $this->request('PATCH', $this->valuationUri(), ['boostShipTypeId' => self::PORPOISE, 'mountedCompressorKinds' => ['ice']]);

        self::assertSame([400, 'The ice compressor cannot be mounted on a Porpoise'], $this->errorDetail());
        $this->as($this->aria);
        $this->request('GET', $this->valuationUri());
        self::assertSame([null, []], $this->boostOf($this->responseJson()['settings'] ?? []));
    }

    public function testMoonCompressorWithoutBoostShipIsAViolationOnTheMountedCompressorKinds(): void
    {
        $this->as($this->aria);

        $this->request('PATCH', $this->valuationUri(), ['mountedCompressorKinds' => ['moon']]);

        self::assertSame([422, ['mountedCompressorKinds']], [$this->client->getResponse()->getStatusCode(), $this->violatedFields()]);
    }

    public function testBoostShipSetBackToNullEmptiesTheMountedCompressors(): void
    {
        $this->as($this->aria);
        $this->request('PATCH', $this->valuationUri(), ['boostShipTypeId' => self::ORCA, 'mountedCompressorKinds' => ['asteroid', 'gas', 'ice', 'mercoxit', 'moon']]);
        self::assertSame([self::ORCA, ['asteroid', 'gas', 'ice', 'mercoxit', 'moon']], $this->boostOf($this->responseJson()['settings'] ?? []));
        $this->as($this->aria);

        $this->request('PATCH', $this->valuationUri(), ['boostShipTypeId' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([null, []], $this->boostOf($this->responseJson()['settings'] ?? []));
    }

    public function testUnknownHullUnknownOrDuplicatedKindAndMissingListAreViolationsOnTheirField(): void
    {
        $bodies = [
            ['boostShipTypeId' => 12_345, 'mountedCompressorKinds' => []],
            ['boostShipTypeId' => self::ORCA, 'mountedCompressorKinds' => ['asteroid', 'asteroid']],
            ['boostShipTypeId' => self::ORCA, 'mountedCompressorKinds' => ['plasma']],
            ['boostShipTypeId' => self::ORCA],
        ];
        $outcomes = [];
        foreach ($bodies as $body) {
            $this->as($this->aria);
            $this->request('PATCH', $this->valuationUri(), $body);
            $outcomes[] = [$this->client->getResponse()->getStatusCode(), $this->violatedFields()];
        }

        self::assertSame(
            [[422, ['boostShipTypeId']], [422, ['mountedCompressorKinds']], [422, ['mountedCompressorKinds']], [422, ['mountedCompressorKinds']]],
            $outcomes,
        );
    }

    public function testSettingsPatchedWithoutTheBoostFieldsKeepsTheBoostShipAndItsCompressors(): void
    {
        $this->as($this->aria);
        $this->request('PATCH', $this->valuationUri(), ['boostShipTypeId' => self::ORCA, 'mountedCompressorKinds' => ['asteroid']]);
        $this->as($this->aria);

        $this->request('PATCH', $this->valuationUri(), ['boosterCutPercent' => 10]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([10, self::ORCA, ['asteroid']], [$this->responseJson()['settings']['boosterCutPercent'] ?? null, ...$this->boostOf($this->responseJson()['settings'] ?? [])]);
    }

    public function testPlainMemberCannotSetTheBoostShip(): void
    {
        $this->as($this->brann);

        $this->request('PATCH', $this->valuationUri(), ['boostShipTypeId' => self::ORCA, 'mountedCompressorKinds' => ['asteroid']]);

        self::assertSame([403, self::NOT_ADMIN_REFUSAL], $this->errorDetail());
    }

    public function testSharesAndMinedValuesOfE1AreTheSameWithAPorpoiseAndWithAnOrca(): void
    {
        $this->closingE1();
        $this->updateValuation(['valuationSide' => 'sell', 'boosterCutPercent' => 10, 'boosterMemberId' => (string) $this->cyraMembership->getId()]);
        $outcomes = [];
        foreach ([
            ['boostShipTypeId' => self::PORPOISE, 'mountedCompressorKinds' => ['asteroid', 'gas']],
            ['boostShipTypeId' => self::ORCA, 'mountedCompressorKinds' => ['asteroid', 'gas', 'ice', 'mercoxit', 'moon']],
        ] as $boost) {
            $this->updateValuation($boost);
            $this->as($this->aria);
            $this->request('GET', $this->valuationUri());
            $valuation = $this->responseJson();
            $outcomes[] = [
                $this->boostOf($valuation['settings'] ?? []),
                $valuation['totalValue'] ?? null,
                array_map(floatval(...), array_column($valuation['participants'] ?? [], 'minedValue')),
                array_column($valuation['participants'] ?? [], 'share'),
            ];
        }

        $minedValuesAndShares = [self::E1_TOTAL_VALUE, [2_699_093.0, 2_868_460.0, 176.0], [2_429_184, 2_581_614, 158]];
        self::assertSame(
            [
                [[self::PORPOISE, ['asteroid', 'gas']], ...$minedValuesAndShares],
                [[self::ORCA, ['asteroid', 'gas', 'ice', 'mercoxit', 'moon']], ...$minedValuesAndShares],
            ],
            $outcomes,
        );
    }

    private function creation(array $boost = []): array
    {
        return $boost + [
            'label' => 'Ice belt — dimanche',
            'plannedStartAt' => '2026-11-08T18:00:00+00:00',
            'visibility' => 'corporation',
            'splitMode' => 'value_prorata',
            'characterIds' => [(string) $this->miningCharacters['Elia Varr']->getId()],
        ];
    }

    private function boostOf(array $settings): array
    {
        return [
            array_key_exists('boostShipTypeId', $settings) ? $settings['boostShipTypeId'] : 'missing',
            array_key_exists('mountedCompressorKinds', $settings) ? $settings['mountedCompressorKinds'] : 'missing',
        ];
    }

    private function valuationUri(): string
    {
        return sprintf('/api/mining-operations/%s/valuation', $this->spodumain->getId());
    }

    private function violatedFields(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $path): string => (string) preg_replace('/\[.*$/', '', $path),
            array_column($this->responseJson()['violations'] ?? [], 'propertyPath'),
        )));
    }
}
