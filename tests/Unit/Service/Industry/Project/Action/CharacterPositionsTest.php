<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Action;

use App\Entity\Character;
use App\Entity\User;
use App\Service\ESI\CharacterLocation;
use App\Service\ESI\CharacterLocationReader;
use App\Service\Industry\Project\Action\CharacterPositions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CharacterPositions::class)]
#[Group('issue-1300')]
final class CharacterPositionsTest extends TestCase
{
    private const int MAIN_EVE_CHARACTER_ID = 2_112_130_001;
    private const int ALT_EVE_CHARACTER_ID = 2_112_130_002;
    private const int OFFLINE_ALT_EVE_CHARACTER_ID = 2_112_130_003;
    private const int OTHER_PILOT_EVE_CHARACTER_ID = 2_112_130_099;

    public function testPositionsAreTheReadableLocationsOfEveryCharacterOfTheUser(): void
    {
        $user = $this->userWithCharacters(self::MAIN_EVE_CHARACTER_ID, self::ALT_EVE_CHARACTER_ID);
        $mainLocation = new CharacterLocation(30_000_144, null, 1_040_000_001_000, true, new \DateTimeImmutable('2026-11-22T10:15:00Z'));
        $altLocation = new CharacterLocation(30_000_142, 60_003_760, null, false, null);
        $reader = $this->readerLocating([self::MAIN_EVE_CHARACTER_ID => $mainLocation, self::ALT_EVE_CHARACTER_ID => $altLocation]);

        self::assertSame([$mainLocation, $altLocation], (new CharacterPositions($reader))->of($user));
    }

    public function testCharacterWhoseLocationCannotBeReadIsLeftOut(): void
    {
        $user = $this->userWithCharacters(self::MAIN_EVE_CHARACTER_ID, self::OFFLINE_ALT_EVE_CHARACTER_ID, self::ALT_EVE_CHARACTER_ID);
        $altLocation = new CharacterLocation(30_000_142, 60_003_760, null, true, null);
        $reader = $this->readerLocating([self::MAIN_EVE_CHARACTER_ID => null, self::OFFLINE_ALT_EVE_CHARACTER_ID => null, self::ALT_EVE_CHARACTER_ID => $altLocation]);

        self::assertSame([$altLocation], (new CharacterPositions($reader))->of($user));
    }

    public function testNoReadableLocationGivesNoPosition(): void
    {
        $user = $this->userWithCharacters(self::MAIN_EVE_CHARACTER_ID);
        $reader = $this->readerLocating([self::MAIN_EVE_CHARACTER_ID => null]);

        self::assertSame([], (new CharacterPositions($reader))->of($user));
    }

    public function testOnlyTheCharactersOfTheGivenUserAreRead(): void
    {
        $user = $this->userWithCharacters(self::MAIN_EVE_CHARACTER_ID, self::ALT_EVE_CHARACTER_ID);
        $this->userWithCharacters(self::OTHER_PILOT_EVE_CHARACTER_ID);
        $readEveCharacterIds = [];
        $reader = $this->createStub(CharacterLocationReader::class);
        $reader->method('read')->willReturnCallback(static function (Character $character) use (&$readEveCharacterIds): ?CharacterLocation {
            $readEveCharacterIds[] = $character->getEveCharacterId();

            return null;
        });

        (new CharacterPositions($reader))->of($user);

        self::assertSame([self::MAIN_EVE_CHARACTER_ID, self::ALT_EVE_CHARACTER_ID], $readEveCharacterIds);
    }

    private function readerLocating(array $locationsByEveCharacterId): CharacterLocationReader
    {
        $reader = $this->createStub(CharacterLocationReader::class);
        $reader->method('read')->willReturnCallback(
            static fn (Character $character): ?CharacterLocation => $locationsByEveCharacterId[$character->getEveCharacterId()],
        );

        return $reader;
    }

    private function userWithCharacters(int ...$eveCharacterIds): User
    {
        $user = new User();
        foreach ($eveCharacterIds as $eveCharacterId) {
            $character = (new Character())->setEveCharacterId($eveCharacterId)->setName('Pilot '.$eveCharacterId);
            $user->addCharacter($character);
            if ($user->getMainCharacter() === null) {
                $user->setMainCharacter($character);
            }
        }

        return $user;
    }
}
