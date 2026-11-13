<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Entity\EveToken;
use App\Exception\EsiApiException;
use App\Repository\CachedStructureRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\ESI\AssetsService;
use App\Service\ESI\EsiClient;
use App\Service\TypeNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AssetsService::class)]
#[Group('issue-784')]
final class AssetsServiceSingleItemNameTest extends TestCase
{
    private const int EVE_CHARACTER_ID = 2_113_784_011;
    private const int CORPORATION_ID = 98_784_011;
    private const int RIFTER_ITEM_ID = 1_050_000_784_101;
    private const int STATION_CONTAINER_ITEM_ID = 1_050_000_784_102;

    /** @var list<array{endpoint: string, body: array<int|string, mixed>, token: ?EveToken}> */
    private array $namesRequests = [];

    /** @var list<mixed>|\Throwable */
    private array|\Throwable $namesAnswer = [];

    protected function setUp(): void
    {
        $this->namesRequests = [];
        $this->namesAnswer = [];
    }

    public function testCharacterItemNameIsRequestedAloneFromTheCharacterNamesEndpointWithTheGivenToken(): void
    {
        $token = new EveToken();
        $this->namesAnswer = [['item_id' => self::RIFTER_ITEM_ID, 'name' => 'Hauler One']];

        $name = $this->assetsService()->resolveSingleItemName(self::RIFTER_ITEM_ID, $token, self::EVE_CHARACTER_ID, null);

        self::assertSame('Hauler One', $name);
        self::assertSame(
            [['endpoint' => '/characters/' . self::EVE_CHARACTER_ID . '/assets/names/', 'body' => [self::RIFTER_ITEM_ID], 'token' => $token]],
            $this->namesRequests,
        );
    }

    public function testCorporationItemNameIsRequestedAloneFromTheCorporationNamesEndpointWithTheGivenToken(): void
    {
        $token = new EveToken();
        $this->namesAnswer = [['item_id' => self::STATION_CONTAINER_ITEM_ID, 'name' => 'Corp Ammo Box']];

        $name = $this->assetsService()->resolveSingleItemName(self::STATION_CONTAINER_ITEM_ID, $token, null, self::CORPORATION_ID);

        self::assertSame('Corp Ammo Box', $name);
        self::assertSame(
            [['endpoint' => '/corporations/' . self::CORPORATION_ID . '/assets/names/', 'body' => [self::STATION_CONTAINER_ITEM_ID], 'token' => $token]],
            $this->namesRequests,
        );
    }

    public function testEsiFailureReachesTheCallerInsteadOfBeingSwallowed(): void
    {
        $failure = new EsiApiException('Requested item is not a nameable asset', 404);
        $this->namesAnswer = $failure;

        try {
            $this->assetsService()->resolveSingleItemName(self::STATION_CONTAINER_ITEM_ID, new EveToken(), self::EVE_CHARACTER_ID, null);
            self::fail('The ESI failure was swallowed');
        } catch (EsiApiException $thrown) {
            self::assertSame($failure, $thrown);
        }

        self::assertCount(1, $this->namesRequests);
    }

    /**
     * @return iterable<string, array{list<mixed>}>
     */
    public static function malformedNamesAnswers(): iterable
    {
        yield 'name absent' => [[['item_id' => self::RIFTER_ITEM_ID]]];
        yield 'name is an integer' => [[['item_id' => self::RIFTER_ITEM_ID, 'name' => 1234]]];
        yield 'name is a float' => [[['item_id' => self::RIFTER_ITEM_ID, 'name' => 12.5]]];
        yield 'name is null' => [[['item_id' => self::RIFTER_ITEM_ID, 'name' => null]]];
    }

    /**
     * @param list<mixed> $answer
     */
    #[DataProvider('malformedNamesAnswers')]
    public function testMalformedEsiEntryRaisesAnExplicitErrorInsteadOfADefaultName(array $answer): void
    {
        $this->namesAnswer = $answer;

        $this->expectException(EsiApiException::class);

        $this->assetsService()->resolveSingleItemName(self::RIFTER_ITEM_ID, new EveToken(), self::EVE_CHARACTER_ID, null);
    }

    private function assetsService(): AssetsService
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('post')->willReturnCallback(
            function (string $endpoint, array $body, ?EveToken $token = null): array {
                $this->namesRequests[] = ['endpoint' => $endpoint, 'body' => $body, 'token' => $token];

                if ($this->namesAnswer instanceof \Throwable) {
                    throw $this->namesAnswer;
                }

                return $this->namesAnswer;
            },
        );

        return new AssetsService(
            $esiClient,
            $this->createStub(MapSolarSystemRepository::class),
            $this->createStub(CachedStructureRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new TypeNameResolver($this->createStub(InvTypeRepository::class)),
            $this->createStub(StaStationRepository::class),
            $this->createStub(InvTypeRepository::class),
        );
    }
}
