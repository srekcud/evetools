<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Character;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Security\AdminCharacterLister;
use App\Security\AdminChecker;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('issue-654')]
final class AdminCharacterListerTest extends TestCase
{
    private const MAIN_ADMIN_ID = 90000001;
    private const ALT_ADMIN_ID = 90000002;
    private const NEVER_LOGGED_IN_ID = 90000003;
    private const ORPHAN_ID = 90000004;

    public function testAdminWhoIsTheMainCharacterOfAUserIsListedWithItsNameAndAUser(): void
    {
        $main = $this->characterOf(self::MAIN_ADMIN_ID, 'Mira Tolland');
        $this->userWithMain($main);

        $entries = $this->listerFor((string) self::MAIN_ADMIN_ID, [$main])->all();

        self::assertCount(1, $entries);
        self::assertSame(self::MAIN_ADMIN_ID, $entries[0]->eveCharacterId);
        self::assertSame('Mira Tolland', $entries[0]->characterName);
        self::assertTrue($entries[0]->hasUser);
    }

    public function testListedIdThatIsOnlyAnAltOfAUserHasNoUserWithThatMainCharacter(): void
    {
        $main = $this->characterOf(self::MAIN_ADMIN_ID, 'Mira Tolland');
        $alt = $this->characterOf(self::ALT_ADMIN_ID, 'Dax Orrin');
        $user = $this->userWithMain($main);
        $user->addCharacter($alt);

        $entries = $this->listerFor((string) self::ALT_ADMIN_ID, [$main, $alt])->all();

        self::assertSame('Dax Orrin', $entries[0]->characterName);
        self::assertFalse($entries[0]->hasUser);
    }

    public function testKnownCharacterWithoutUserIsListedWithItsNameAndNoUser(): void
    {
        $detached = $this->characterOf(self::ORPHAN_ID, 'Kel Varro');

        $entries = $this->listerFor((string) self::ORPHAN_ID, [$detached])->all();

        self::assertSame('Kel Varro', $entries[0]->characterName);
        self::assertFalse($entries[0]->hasUser);
    }

    public function testListedIdOfACharacterNeverSeenByTheAppHasNoNameAndNoUser(): void
    {
        $entries = $this->listerFor((string) self::NEVER_LOGGED_IN_ID, [])->all();

        self::assertCount(1, $entries);
        self::assertSame(self::NEVER_LOGGED_IN_ID, $entries[0]->eveCharacterId);
        self::assertNull($entries[0]->characterName);
        self::assertFalse($entries[0]->hasUser);
    }

    public function testEntriesFollowTheOrderOfTheConfiguration(): void
    {
        $first = $this->characterOf(self::ALT_ADMIN_ID, 'Dax Orrin');
        $second = $this->characterOf(self::MAIN_ADMIN_ID, 'Mira Tolland');

        $entries = $this->listerFor(self::ALT_ADMIN_ID . ',' . self::NEVER_LOGGED_IN_ID . ',' . self::MAIN_ADMIN_ID, [$first, $second])->all();

        self::assertSame(
            [self::ALT_ADMIN_ID, self::NEVER_LOGGED_IN_ID, self::MAIN_ADMIN_ID],
            array_map(static fn ($entry): int => $entry->eveCharacterId, $entries),
        );
    }

    public function testNoAdminConfiguredGivesAnEmptyList(): void
    {
        self::assertSame([], $this->listerFor('', [])->all());
    }

    /**
     * @param list<Character> $knownCharacters
     */
    private function listerFor(string $configuredIds, array $knownCharacters): AdminCharacterLister
    {
        $repository = $this->createStub(CharacterRepository::class);
        $repository->method('findByEveCharacterId')->willReturnCallback(
            static function (int $eveCharacterId) use ($knownCharacters): ?Character {
                foreach ($knownCharacters as $character) {
                    if ($character->getEveCharacterId() === $eveCharacterId) {
                        return $character;
                    }
                }

                return null;
            },
        );

        return new AdminCharacterLister(new AdminChecker($configuredIds), $repository);
    }

    private function characterOf(int $eveCharacterId, string $name): Character
    {
        return (new Character())->setEveCharacterId($eveCharacterId)->setName($name);
    }

    private function userWithMain(Character $main): User
    {
        $user = new User();
        $user->addCharacter($main);
        $user->setMainCharacter($main);

        return $user;
    }
}
