<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Dto\CharacterInfoDto;
use App\Exception\EsiApiException;
use App\Service\ESI\CharacterService;
use App\Service\ESI\EsiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(CharacterService::class)]
#[Group('issue-211')]
final class CharacterServiceTest extends TestCase
{
    private const int EVE_CHARACTER_ID = 12345;
    private const string CHARACTER_ENDPOINT = '/characters/12345/';

    private EsiClient&Stub $esiClient;
    private CharacterService $characterService;
    private array $esiResponses = [];
    private array $readEndpoints = [];

    protected function setUp(): void
    {
        $this->esiClient = $this->createStub(EsiClient::class);
        $this->esiClient->method('getWithCache')->willReturnCallback(
            function (string $endpoint): array {
                $this->readEndpoints[] = $endpoint;
                if (!array_key_exists($endpoint, $this->esiResponses)) {
                    throw EsiApiException::notFound(endpoint: $endpoint);
                }

                return $this->esiResponses[$endpoint];
            },
        );
        $this->characterService = new CharacterService($this->esiClient);
    }

    public function testCharacterInfoTakesCorporationAndAllianceReadFromEsi(): void
    {
        $this->esiResponses = [
            self::CHARACTER_ENDPOINT => ['name' => 'Spec Industrialist', 'corporation_id' => 98000002, 'alliance_id' => 99000001],
            '/corporations/98000002/' => ['name' => 'New Corp'],
            '/alliances/99000001/' => ['name' => 'New Alliance'],
        ];

        $characterInfo = $this->characterService->getCharacterInfo(self::EVE_CHARACTER_ID);

        self::assertEquals(
            new CharacterInfoDto(self::EVE_CHARACTER_ID, 'Spec Industrialist', 98000002, 'New Corp', 99000001, 'New Alliance'),
            $characterInfo,
        );
    }

    public function testCharacterInfoWithoutAllianceReadsNoAlliance(): void
    {
        $this->esiResponses = [
            self::CHARACTER_ENDPOINT => ['name' => 'Spec Industrialist', 'corporation_id' => 98000001],
            '/corporations/98000001/' => ['name' => 'Old Corp'],
        ];

        $characterInfo = $this->characterService->getCharacterInfo(self::EVE_CHARACTER_ID);

        self::assertEquals(
            new CharacterInfoDto(self::EVE_CHARACTER_ID, 'Spec Industrialist', 98000001, 'Old Corp', null, null),
            $characterInfo,
        );
        self::assertSame([self::CHARACTER_ENDPOINT, '/corporations/98000001/'], $this->readEndpoints);
    }

    #[DataProvider('characterResponsesWithoutIntegerCorporationId')]
    public function testCharacterResponseWithoutIntegerCorporationIdIsRejectedNamingTheEndpoint(array $characterResponse): void
    {
        $this->esiResponses = [
            self::CHARACTER_ENDPOINT => $characterResponse,
            '/corporations/98000002/' => ['name' => 'New Corp'],
        ];

        $failure = null;
        try {
            $this->characterService->getCharacterInfo(self::EVE_CHARACTER_ID);
        } catch (\Throwable $thrown) {
            $failure = $thrown;
        }

        self::assertInstanceOf(\UnexpectedValueException::class, $failure);
        self::assertStringContainsString(self::CHARACTER_ENDPOINT, $failure->getMessage());
        self::assertStringContainsString('corporation_id', $failure->getMessage());
    }

    public static function characterResponsesWithoutIntegerCorporationId(): iterable
    {
        yield 'E5 corporation_id missing' => [['name' => 'Spec Industrialist']];
        yield 'corporation_id null' => [['name' => 'Spec Industrialist', 'corporation_id' => null]];
        yield 'corporation_id as string' => [['name' => 'Spec Industrialist', 'corporation_id' => '98000002']];
    }
}
