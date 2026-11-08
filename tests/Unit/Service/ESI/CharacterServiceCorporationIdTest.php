<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Service\ESI\CharacterService;
use App\Service\ESI\EsiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CharacterService::class)]
#[Group('issue-369')]
final class CharacterServiceCorporationIdTest extends TestCase
{
    private const int EVE_CHARACTER_ID = 2_112_000_001;
    private const int CORPORATION_ID = 98_000_007;
    private const string CHARACTER_ENDPOINT = '/characters/2112000001/';

    public function testItReadsTheCurrentCorporationFromThePublicCharacterEndpoint(): void
    {
        $readEndpoints = [];
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getWithCache')->willReturnCallback(function (string $endpoint) use (&$readEndpoints): array {
            $readEndpoints[] = $endpoint;

            return ['name' => 'Ilyra Voss', 'corporation_id' => self::CORPORATION_ID, 'alliance_id' => 99_000_005];
        });

        $corporationId = (new CharacterService($esiClient))->readCorporationId(self::EVE_CHARACTER_ID);

        self::assertSame([self::CORPORATION_ID, [self::CHARACTER_ENDPOINT]], [$corporationId, $readEndpoints]);
    }

    #[DataProvider('responsesWithoutIntegerCorporationId')]
    public function testAResponseWithoutIntegerCorporationIdIsRejectedNamingTheEndpoint(array $response): void
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getWithCache')->willReturn($response);

        $failure = null;
        try {
            (new CharacterService($esiClient))->readCorporationId(self::EVE_CHARACTER_ID);
        } catch (\Throwable $thrown) {
            $failure = $thrown;
        }

        self::assertInstanceOf(\UnexpectedValueException::class, $failure);
        self::assertStringContainsString(self::CHARACTER_ENDPOINT, $failure->getMessage());
        self::assertStringContainsString('corporation_id', $failure->getMessage());
    }

    public static function responsesWithoutIntegerCorporationId(): iterable
    {
        yield 'corporation_id missing' => [['name' => 'Ilyra Voss']];
        yield 'corporation_id null' => [['name' => 'Ilyra Voss', 'corporation_id' => null]];
        yield 'corporation_id as string' => [['name' => 'Ilyra Voss', 'corporation_id' => '98000007']];
        yield 'corporation_id as float' => [['name' => 'Ilyra Voss', 'corporation_id' => 98_000_007.0]];
    }
}
