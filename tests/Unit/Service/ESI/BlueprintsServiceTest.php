<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Dto\BlueprintDto;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Exception\EsiApiException;
use App\Service\ESI\BlueprintsService;
use App\Service\ESI\EsiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlueprintsService::class)]
#[Group('issue-357')]
final class BlueprintsServiceTest extends TestCase
{
    private const int EVE_CHARACTER_ID = 2_113_357_101;
    private const int CORPORATION_ID = 98_357_101;

    private const array ORIGINAL_ROW = [
        'item_id' => 1_040_357_001,
        'location_flag' => 'Hangar',
        'location_id' => 60_003_760,
        'material_efficiency' => 10,
        'quantity' => -1,
        'runs' => -1,
        'time_efficiency' => 20,
        'type_id' => 16_230,
    ];

    private const array COPY_ROW = [
        'item_id' => 1_040_357_002,
        'location_flag' => 'CorpSAG3',
        'location_id' => 1_040_000_000_001,
        'material_efficiency' => 2,
        'quantity' => -2,
        'runs' => 50,
        'time_efficiency' => 4,
        'type_id' => 17_300,
    ];

    private const array INTEGER_KEYS = [
        'item_id',
        'location_id',
        'material_efficiency',
        'quantity',
        'runs',
        'time_efficiency',
        'type_id',
    ];

    public function testCharacterOriginalKeepsItsMinusOneQuantityAndRuns(): void
    {
        $character = $this->character();
        $esiClient = $this->createMock(EsiClient::class);
        $esiClient->expects(self::once())
            ->method('getPaginated')
            ->with('/characters/' . self::EVE_CHARACTER_ID . '/blueprints/', $character->getEveToken())
            ->willReturn([self::ORIGINAL_ROW]);

        $blueprints = (new BlueprintsService($esiClient))->getCharacterBlueprints($character);

        self::assertEquals(
            [new BlueprintDto(1_040_357_001, 16_230, 60_003_760, 'Hangar', -1, 10, 20, -1)],
            $blueprints,
        );
    }

    public function testCorporationCopyKeepsItsRunsAndItsDivisionFlag(): void
    {
        $character = $this->character();
        $esiClient = $this->createMock(EsiClient::class);
        $esiClient->expects(self::once())
            ->method('getPaginated')
            ->with('/corporations/' . self::CORPORATION_ID . '/blueprints/', $character->getEveToken())
            ->willReturn([self::COPY_ROW]);

        $blueprints = (new BlueprintsService($esiClient))->getCorporationBlueprints($character);

        self::assertEquals(
            [new BlueprintDto(1_040_357_002, 17_300, 1_040_000_000_001, 'CorpSAG3', -2, 2, 4, 50)],
            $blueprints,
        );
    }

    #[DataProvider('missingFields')]
    public function testRowWithoutAFieldRaisesAnExplicitErrorNamingIt(string $field): void
    {
        $row = self::ORIGINAL_ROW;
        unset($row[$field]);

        $this->expectException(EsiApiException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($field, '/') . '/');

        $this->serviceReturning([$row])->getCharacterBlueprints($this->character());
    }

    #[DataProvider('fieldsOfTheWrongType')]
    public function testRowWithAFieldOfTheWrongTypeRaisesAnExplicitErrorNamingIt(string $field, mixed $wrongValue): void
    {
        $row = self::ORIGINAL_ROW;
        $row[$field] = $wrongValue;

        $this->expectException(EsiApiException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($field, '/') . '/');

        $this->serviceReturning([$row])->getCharacterBlueprints($this->character());
    }

    public static function missingFields(): iterable
    {
        foreach (array_keys(self::ORIGINAL_ROW) as $field) {
            yield $field => [$field];
        }
    }

    public static function fieldsOfTheWrongType(): iterable
    {
        foreach (self::INTEGER_KEYS as $field) {
            yield $field . ' as a string' => [$field, '12'];
            yield $field . ' as a float' => [$field, 1.5];
            yield $field . ' as null' => [$field, null];
        }
        yield 'location_flag as an integer' => ['location_flag', 5];
        yield 'location_flag as a float' => ['location_flag', 1.5];
        yield 'location_flag as null' => ['location_flag', null];
    }

    private function serviceReturning(array $rows): BlueprintsService
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginated')->willReturn($rows);

        return new BlueprintsService($esiClient);
    }

    private function character(): Character
    {
        $character = (new Character())
            ->setEveCharacterId(self::EVE_CHARACTER_ID)
            ->setName('Aria Solberg')
            ->setCorporationId(self::CORPORATION_ID)
            ->setCorporationName('Solberg Industries');
        $character->setEveToken((new EveToken())->setScopes(['esi-characters.read_blueprints.v1', 'esi-corporations.read_blueprints.v1']));

        return $character;
    }
}
