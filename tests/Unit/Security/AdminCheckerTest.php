<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Character;
use App\Entity\User;
use App\Security\AdminChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Un admin est identifié par l'eveCharacterId de son personnage principal,
 * listé dans la variable d'environnement ADMIN_CHARACTER_IDS (séparée par des virgules).
 * Le nom du personnage n'est jamais utilisé.
 */
#[CoversClass(AdminChecker::class)]
class AdminCheckerTest extends TestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 90000001;
    private const OTHER_EVE_CHARACTER_ID = 90000002;
    private const THIRD_EVE_CHARACTER_ID = 90000003;
    private const ADMIN_NAME = 'Admin Pilot';

    public function testUserWhoseMainCharacterIdIsListedIsAdmin(): void
    {
        $checker = new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID);

        $this->assertTrue($checker->isAdmin($this->userWithMainCharacter(self::ADMIN_EVE_CHARACTER_ID, self::ADMIN_NAME)));
    }

    public function testUserWithSameNameButDifferentEveCharacterIdIsNotAdmin(): void
    {
        $checker = new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID);

        $this->assertFalse($checker->isAdmin($this->userWithMainCharacter(self::OTHER_EVE_CHARACTER_ID, self::ADMIN_NAME)));
    }

    public function testUserWithoutMainCharacterIsNotAdmin(): void
    {
        $checker = new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID);

        $this->assertFalse($checker->isAdmin(new User()));
    }

    public function testListedIdOnNonMainCharacterDoesNotMakeUserAdmin(): void
    {
        $checker = new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID);
        $user = $this->userWithMainCharacter(self::OTHER_EVE_CHARACTER_ID, 'Main Pilot');
        $user->addCharacter($this->character(self::ADMIN_EVE_CHARACTER_ID, 'Alt Pilot'));

        $this->assertFalse($checker->isAdmin($user));
    }

    public function testEveryIdOfACommaSeparatedListWithSpacesIsAdmin(): void
    {
        $checker = new AdminChecker(sprintf(' %d , %d ,%d', self::ADMIN_EVE_CHARACTER_ID, self::OTHER_EVE_CHARACTER_ID, self::THIRD_EVE_CHARACTER_ID));

        $this->assertTrue($checker->isAdmin($this->userWithMainCharacter(self::ADMIN_EVE_CHARACTER_ID, 'Pilot One')));
        $this->assertTrue($checker->isAdmin($this->userWithMainCharacter(self::OTHER_EVE_CHARACTER_ID, 'Pilot Two')));
        $this->assertTrue($checker->isAdmin($this->userWithMainCharacter(self::THIRD_EVE_CHARACTER_ID, 'Pilot Three')));
        $this->assertFalse($checker->isAdmin($this->userWithMainCharacter(90000004, 'Pilot Four')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function noAdminConfigurations(): iterable
    {
        yield 'empty value' => [''];
        yield 'only spaces' => ['   '];
        yield 'only commas and spaces' => [' , ,, '];
    }

    #[DataProvider('noAdminConfigurations')]
    public function testEmptyConfigurationGrantsNoAdmin(string $adminCharacterIds): void
    {
        $checker = new AdminChecker($adminCharacterIds);

        $this->assertFalse($checker->isAdmin($this->userWithMainCharacter(self::ADMIN_EVE_CHARACTER_ID, self::ADMIN_NAME)));
    }

    public function testCharacterNameInConfigurationIsNotAcceptedAsAdmin(): void
    {
        $checker = new AdminChecker(self::ADMIN_NAME);

        $this->assertFalse($checker->isAdmin($this->userWithMainCharacter(self::ADMIN_EVE_CHARACTER_ID, self::ADMIN_NAME)));
    }

    private function userWithMainCharacter(int $eveCharacterId, string $name): User
    {
        $user = new User();
        $mainCharacter = $this->character($eveCharacterId, $name);
        $user->addCharacter($mainCharacter);
        $user->setMainCharacter($mainCharacter);

        return $user;
    }

    private function character(int $eveCharacterId, string $name): Character
    {
        return (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name);
    }
}
