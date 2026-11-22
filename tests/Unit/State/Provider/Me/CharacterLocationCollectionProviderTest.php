<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Me;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Me\CharacterLocationResource;
use App\Entity\Character;
use App\Entity\User;
use App\Service\ESI\CharacterLocation;
use App\Service\ESI\CharacterLocationReader;
use App\State\Provider\Me\CharacterLocationCollectionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(CharacterLocationCollectionProvider::class)]
#[CoversClass(CharacterLocationResource::class)]
#[Group('issue-1297')]
final class CharacterLocationCollectionProviderTest extends TestCase
{
    private const int MAIN_EVE_CHARACTER_ID = 2_112_000_001;
    private const int ALT_EVE_CHARACTER_ID = 2_112_000_002;
    private const int OTHER_MEMBER_EVE_CHARACTER_ID = 2_112_000_099;
    private const int JITA_SYSTEM_ID = 30_000_142;
    private const int JITA_FORTIZAR_STATION_ID = 60_003_760;
    private const int KEEPSTAR_STRUCTURE_ID = 1_040_000_000_001;

    public function testEveryCharacterOfTheUserIsLocatedWithItsOwnPosition(): void
    {
        $user = $this->userWithCharacters([self::MAIN_EVE_CHARACTER_ID => 'Main Pilot', self::ALT_EVE_CHARACTER_ID => 'Alt Pilot']);
        $reader = $this->readerLocating([
            self::MAIN_EVE_CHARACTER_ID => new CharacterLocation(self::JITA_SYSTEM_ID, self::JITA_FORTIZAR_STATION_ID, null, true, new \DateTimeImmutable('2026-11-22T10:15:00Z')),
            self::ALT_EVE_CHARACTER_ID => new CharacterLocation(self::JITA_SYSTEM_ID, null, self::KEEPSTAR_STRUCTURE_ID, false, null),
        ]);

        $resources = $this->provide($user, $reader);

        self::assertSame(
            [
                [self::MAIN_EVE_CHARACTER_ID, self::JITA_SYSTEM_ID, self::JITA_FORTIZAR_STATION_ID, null, true, '2026-11-22T10:15:00+00:00'],
                [self::ALT_EVE_CHARACTER_ID, self::JITA_SYSTEM_ID, null, self::KEEPSTAR_STRUCTURE_ID, false, null],
            ],
            array_map(
                static fn (CharacterLocationResource $resource): array => [$resource->eveCharacterId, $resource->solarSystemId, $resource->stationId, $resource->structureId, $resource->online, $resource->lastLoginAt],
                $resources,
            ),
        );
    }

    public function testCharacterWhoseLocationCannotBeReadIsListedWithUnknownFields(): void
    {
        $user = $this->userWithCharacters([self::MAIN_EVE_CHARACTER_ID => 'Main Pilot']);
        $reader = $this->readerLocating([self::MAIN_EVE_CHARACTER_ID => null]);

        $resources = $this->provide($user, $reader);

        self::assertCount(1, $resources);
        self::assertSame(
            [self::MAIN_EVE_CHARACTER_ID, null, null, null, null, null],
            [$resources[0]->eveCharacterId, $resources[0]->solarSystemId, $resources[0]->stationId, $resources[0]->structureId, $resources[0]->online, $resources[0]->lastLoginAt],
        );
    }

    public function testOnlyTheCharactersOfTheAuthenticatedUserAreRead(): void
    {
        $user = $this->userWithCharacters([self::MAIN_EVE_CHARACTER_ID => 'Main Pilot']);
        $this->userWithCharacters([self::OTHER_MEMBER_EVE_CHARACTER_ID => 'Other Member']);
        $readEveCharacterIds = [];
        $reader = $this->createStub(CharacterLocationReader::class);
        $reader->method('read')->willReturnCallback(static function (Character $character) use (&$readEveCharacterIds): ?CharacterLocation {
            $readEveCharacterIds[] = $character->getEveCharacterId();

            return null;
        });

        $this->provide($user, $reader);

        self::assertSame([self::MAIN_EVE_CHARACTER_ID], $readEveCharacterIds);
    }

    public function testAnonymousRequestIsRefused(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $provider = new CharacterLocationCollectionProvider($security, $this->createStub(CharacterLocationReader::class));

        $this->expectException(UnauthorizedHttpException::class);

        $provider->provide(new GetCollection());
    }

    /**
     * @return CharacterLocationResource[]
     */
    private function provide(User $user, CharacterLocationReader $reader): array
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return (new CharacterLocationCollectionProvider($security, $reader))->provide(new GetCollection());
    }

    /**
     * @param array<int, ?CharacterLocation> $locationsByEveCharacterId
     */
    private function readerLocating(array $locationsByEveCharacterId): CharacterLocationReader
    {
        $reader = $this->createStub(CharacterLocationReader::class);
        $reader->method('read')->willReturnCallback(
            static fn (Character $character): ?CharacterLocation => $locationsByEveCharacterId[$character->getEveCharacterId()] ?? null,
        );

        return $reader;
    }

    /**
     * @param array<int, string> $namesByEveCharacterId first one is the main character
     */
    private function userWithCharacters(array $namesByEveCharacterId): User
    {
        $user = new User();
        foreach ($namesByEveCharacterId as $eveCharacterId => $name) {
            $character = (new Character())->setEveCharacterId($eveCharacterId)->setName($name);
            $user->addCharacter($character);
            if ($user->getMainCharacter() === null) {
                $user->setMainCharacter($character);
            }
        }

        return $user;
    }
}
