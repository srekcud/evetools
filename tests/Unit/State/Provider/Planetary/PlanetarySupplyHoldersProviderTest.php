<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Planetary;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Planetary\PlanetarySupplyHoldersResource;
use App\Dto\Assets\AssetScope;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\PlanetaryColony;
use App\Entity\User;
use App\Repository\AssetBrowseRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Service\Planetary\PlanetaryInputNeedsCalculator;
use App\State\Provider\Planetary\PlanetarySupplyHoldersProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PlanetarySupplyHoldersProvider::class)]
#[Group('issue-1460')]
final class PlanetarySupplyHoldersProviderTest extends TestCase
{
    private const string NOW = '2026-11-28T12:00:00+00:00';
    private const string ASSETS_SCOPE = 'esi-assets.read_assets.v1';

    private const int HOME_STATION = 60_003_760;
    private const string HOME_STATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';
    private const int OTHER_STATION = 60_011_866;

    private const int ROBOTICS = 9848;
    private const int COOLANT = 9832;
    private const int BACTERIA = 2393;

    private User $user;
    private PlanetaryColony $colony;
    private int $nextEveCharacterId = 2_146_000_001;

    /** @var array<string, array<int, array<int, int>>> */
    private array $assetsByCharacterUuid = [];

    /** @var list<array{string|null, list<int>, list<int>}> */
    private array $assetRequests = [];

    private ?User $authenticatedUser = null;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->authenticatedUser = $this->user;
        $this->colony = new PlanetaryColony();
        $this->assetsByCharacterUuid = [];
        $this->assetRequests = [];
    }

    public function testAKnownCharacterListsItsQuantitiesAtTheHomeLocationForTheInputTypesOfTheLaunchpadsOnlyAndCharactersAreSortedByName(): void
    {
        $this->user->setPlanetaryHomeLocation(self::HOME_STATION, self::HOME_STATION_NAME);
        $hold = $this->character('Hold', $this->tokenWith([self::ASSETS_SCOPE]), '2026-11-28T10:00:00+00:00');
        $bravo = $this->character('Bravo', $this->tokenWith([self::ASSETS_SCOPE]), '2026-11-28T11:00:00+00:00');
        $alpha = $this->character('Alpha', $this->tokenWith([self::ASSETS_SCOPE]), '2026-11-28T09:30:00+00:00');
        $this->assetsByCharacterUuid = [
            $this->uuidOf($hold) => [
                self::HOME_STATION => [self::ROBOTICS => 3_000, self::BACTERIA => 40],
                self::OTHER_STATION => [self::ROBOTICS => 9_999],
            ],
            $this->uuidOf($alpha) => [self::HOME_STATION => [self::ROBOTICS => 300, self::COOLANT => 55]],
        ];

        $resource = $this->read([[self::ROBOTICS, self::COOLANT], [self::ROBOTICS]]);

        self::assertSame(self::HOME_STATION, $resource->homeLocationId);
        self::assertSame(self::HOME_STATION_NAME, $resource->homeLocationName);
        self::assertSame(['Alpha', 'Bravo', 'Hold'], array_column($resource->characters, 'characterName'));
        self::assertSame(
            [$alpha->getEveCharacterId(), $bravo->getEveCharacterId(), $hold->getEveCharacterId()],
            array_column($resource->characters, 'characterId'),
        );
        foreach ($resource->characters as $entry) {
            self::assertSame('known', $entry['status']);
            self::assertArrayHasKey('unknownReason', $entry);
            self::assertNull($entry['unknownReason']);
            self::assertFalse($entry['stale']);
        }
        self::assertCount(3, $resource->characters);
        self::assertEqualsCanonicalizing(
            [['typeId' => self::ROBOTICS, 'quantity' => 300], ['typeId' => self::COOLANT, 'quantity' => 55]],
            $resource->characters[0]['quantities'],
        );
        self::assertSame([], $resource->characters[1]['quantities']);
        self::assertSame([['typeId' => self::ROBOTICS, 'quantity' => 3_000]], $resource->characters[2]['quantities']);
        self::assertCount(3, $this->assetRequests);
        self::assertEqualsCanonicalizing(
            [$this->uuidOf($alpha), $this->uuidOf($bravo), $this->uuidOf($hold)],
            array_column($this->assetRequests, 0),
        );
        foreach ($this->assetRequests as [, $rootLocationIds, $typeIds]) {
            self::assertSame([self::HOME_STATION], $rootLocationIds);
            self::assertEqualsCanonicalizing([self::ROBOTICS, self::COOLANT], $typeIds);
        }
    }

    public function testACharacterWithoutTokenOrWithoutTheAssetsScopeIsUnknownWhateverItsLastSyncAndItsStoredAssets(): void
    {
        $this->user->setPlanetaryHomeLocation(self::HOME_STATION, self::HOME_STATION_NAME);
        $withoutToken = $this->character('Alpha', null, null);
        $withoutScope = $this->character('Bravo', $this->tokenWith(['esi-wallet.read_character_wallet.v1']), '2026-11-27T06:00:00+00:00');
        $this->assetsByCharacterUuid = [
            $this->uuidOf($withoutToken) => [self::HOME_STATION => [self::ROBOTICS => 700]],
            $this->uuidOf($withoutScope) => [self::HOME_STATION => [self::ROBOTICS => 800]],
        ];

        $resource = $this->read([[self::ROBOTICS]]);

        self::assertCount(2, $resource->characters);
        foreach ($resource->characters as $entry) {
            self::assertSame('unknown', $entry['status']);
            self::assertSame('missing_assets_scope', $entry['unknownReason']);
            self::assertSame([], $entry['quantities']);
            self::assertFalse($entry['stale']);
        }
        self::assertSame([], $this->assetRequests);
    }

    public function testACharacterWithTheAssetsScopeButNeverSyncedIsUnknownBecauseItsAssetsWereNeverRead(): void
    {
        $this->user->setPlanetaryHomeLocation(self::HOME_STATION, self::HOME_STATION_NAME);
        $this->character('Alpha', $this->tokenWith([self::ASSETS_SCOPE]), null);

        $resource = $this->read([[self::ROBOTICS]]);

        self::assertCount(1, $resource->characters);
        $entry = $resource->characters[0];
        self::assertSame('unknown', $entry['status']);
        self::assertSame('assets_never_read', $entry['unknownReason']);
        self::assertArrayHasKey('assetsReadAt', $entry);
        self::assertNull($entry['assetsReadAt']);
        self::assertFalse($entry['stale']);
        self::assertSame([], $entry['quantities']);
        self::assertSame([], $this->assetRequests);
    }

    public function testTheMissingAssetsScopeIsReportedBeforeAssetsNeverRead(): void
    {
        $this->user->setPlanetaryHomeLocation(self::HOME_STATION, self::HOME_STATION_NAME);
        $this->character('Alpha', null, null);

        $resource = $this->read([[self::ROBOTICS]]);

        self::assertCount(1, $resource->characters);
        self::assertSame('missing_assets_scope', $resource->characters[0]['unknownReason']);
    }

    public function testAssetsReadMoreThanTwentyFourHoursAgoAreStaleButTheirQuantitiesAreStillReported(): void
    {
        $this->user->setPlanetaryHomeLocation(self::HOME_STATION, self::HOME_STATION_NAME);
        $hold = $this->character('Hold', $this->tokenWith([self::ASSETS_SCOPE]), '2026-11-27T06:00:00+00:00');
        $this->assetsByCharacterUuid = [$this->uuidOf($hold) => [self::HOME_STATION => [self::ROBOTICS => 3_000]]];

        $resource = $this->read([[self::ROBOTICS]]);

        self::assertCount(1, $resource->characters);
        $entry = $resource->characters[0];
        self::assertSame('known', $entry['status']);
        self::assertTrue($entry['stale']);
        self::assertSame('2026-11-27T06:00:00+00:00', $entry['assetsReadAt']);
        self::assertSame([['typeId' => self::ROBOTICS, 'quantity' => 3_000]], $entry['quantities']);
    }

    public function testAssetsReadExactlyTwentyFourHoursAgoAreNotStaleAndOneSecondMoreAre(): void
    {
        $this->user->setPlanetaryHomeLocation(self::HOME_STATION, self::HOME_STATION_NAME);
        $this->character('Alpha', $this->tokenWith([self::ASSETS_SCOPE]), '2026-11-27T12:00:00+00:00');
        $this->character('Bravo', $this->tokenWith([self::ASSETS_SCOPE]), '2026-11-27T11:59:59+00:00');

        $resource = $this->read([[self::ROBOTICS]]);

        self::assertSame(['Alpha', 'Bravo'], array_column($resource->characters, 'characterName'));
        self::assertSame([false, true], array_column($resource->characters, 'stale'));
    }

    public function testWithoutHomeLocationEveryCharacterIsReturnedWithItsStatusAndNoQuantityAndNoAssetsAreQueried(): void
    {
        $hold = $this->character('Hold', $this->tokenWith([self::ASSETS_SCOPE]), '2026-11-28T10:00:00+00:00');
        $this->character('Alpha', $this->tokenWith([self::ASSETS_SCOPE]), null);
        $this->character('Bravo', null, null);
        $this->assetsByCharacterUuid = [$this->uuidOf($hold) => [self::HOME_STATION => [self::ROBOTICS => 3_000]]];

        $resource = $this->read([[self::ROBOTICS]]);

        self::assertNull($resource->homeLocationId);
        self::assertNull($resource->homeLocationName);
        self::assertSame(['Alpha', 'Bravo', 'Hold'], array_column($resource->characters, 'characterName'));
        self::assertSame(['unknown', 'unknown', 'known'], array_column($resource->characters, 'status'));
        self::assertSame(['assets_never_read', 'missing_assets_scope', null], array_column($resource->characters, 'unknownReason'));
        self::assertSame([[], [], []], array_column($resource->characters, 'quantities'));
        self::assertSame([], $this->assetRequests);
    }

    public function testAnAnonymousVisitorIsRejected(): void
    {
        $this->authenticatedUser = null;

        $this->expectException(UnauthorizedHttpException::class);

        $this->read([[self::ROBOTICS]]);
    }

    /**
     * @param list<list<int>> $feedTypeIdsByLaunchpad
     */
    private function read(array $feedTypeIdsByLaunchpad): PlanetarySupplyHoldersResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->authenticatedUser);

        $colonyRepository = $this->createStub(PlanetaryColonyRepository::class);
        $colonyRepository->method('findByUser')->willReturnCallback(
            fn (User $user): array => $user === $this->user ? [$this->colony] : throw new \LogicException('The colonies of the connected user are expected'),
        );

        $calculator = $this->createStub(PlanetaryInputNeedsCalculator::class);
        $calculator->method('calculate')->willReturnCallback(
            fn (array $colonies): array => $colonies === [$this->colony]
                ? [
                    'launchpads' => array_map(
                        static fn (array $typeIds): array => ['feeds' => array_map(static fn (int $typeId): array => ['typeId' => $typeId], $typeIds)],
                        $feedTypeIdsByLaunchpad,
                    ),
                ]
                : throw new \LogicException('The colonies of the connected user are expected'),
        );

        $assetBrowseRepository = $this->createStub(AssetBrowseRepository::class);
        $assetBrowseRepository->method('quantitiesByRootLocation')->willReturnCallback(
            function (AssetScope $scope, array $rootLocationIds, array $typeIds): array {
                $this->assetRequests[] = [$scope->characterId, $rootLocationIds, $typeIds];
                $found = [];
                foreach ($this->assetsByCharacterUuid[$scope->characterId] ?? [] as $locationId => $quantities) {
                    if (!\in_array($locationId, $rootLocationIds, true)) {
                        continue;
                    }
                    foreach ($quantities as $typeId => $quantity) {
                        if (\in_array($typeId, $typeIds, true)) {
                            $found[$locationId][$typeId] = $quantity;
                        }
                    }
                }

                return $found;
            },
        );

        $provider = new PlanetarySupplyHoldersProvider($security, $colonyRepository, $calculator, $assetBrowseRepository, new MockClock(self::NOW));
        $resource = $provider->provide(new Get());
        self::assertInstanceOf(PlanetarySupplyHoldersResource::class, $resource);

        return $resource;
    }

    private function character(string $name, ?EveToken $token, ?string $lastSyncAt): Character
    {
        $character = (new Character())
            ->setEveCharacterId($this->nextEveCharacterId++)
            ->setName($name)
            ->setLastSyncAt(null === $lastSyncAt ? null : new \DateTimeImmutable($lastSyncAt));
        (new \ReflectionProperty(Character::class, 'id'))->setValue($character, Uuid::v7());
        if (null !== $token) {
            $character->setEveToken($token);
        }
        $this->user->addCharacter($character);

        return $character;
    }

    /**
     * @param list<string> $scopes
     */
    private function tokenWith(array $scopes): EveToken
    {
        return (new EveToken())
            ->setAccessToken('access-token')
            ->setRefreshTokenEncrypted('refresh-token')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('2026-11-28T13:00:00+00:00'))
            ->setScopes($scopes);
    }

    private function uuidOf(Character $character): string
    {
        return $character->getId()?->toRfc4122() ?? throw new \LogicException('A test character has an identifier');
    }
}
