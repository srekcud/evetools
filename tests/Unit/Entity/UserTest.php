<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Character;
use App\Entity\User;
use App\Enum\AuthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
class UserTest extends TestCase
{
    public function testUserWithoutCharacterHasAValidAuthStatus(): void
    {
        self::assertSame(AuthStatus::Valid, (new User())->getAuthStatus());
    }

    public function testUserWhoseCharactersAreAllValidHasAValidAuthStatus(): void
    {
        $user = new User();
        $user->addCharacter(new Character());
        $user->addCharacter(new Character());

        self::assertSame(AuthStatus::Valid, $user->getAuthStatus());
    }

    public function testUserWithOneRevokedAltHasAnInvalidAuthStatus(): void
    {
        $user = new User();
        $mainCharacter = new Character();
        $altCharacter = new Character();
        $user->addCharacter($mainCharacter);
        $user->addCharacter($altCharacter);
        $user->setMainCharacter($mainCharacter);

        $altCharacter->markAuthInvalid();

        self::assertSame(AuthStatus::Invalid, $user->getAuthStatus());
    }

    public function testUserBecomesValidAgainOnceItsRevokedCharacterIsReconnected(): void
    {
        $user = new User();
        $altCharacter = new Character();
        $user->addCharacter(new Character());
        $user->addCharacter($altCharacter);

        $altCharacter->markAuthInvalid();
        $altCharacter->markAuthValid();

        self::assertSame(AuthStatus::Valid, $user->getAuthStatus());
    }

    public function testAddCharacter(): void
    {
        $user = new User();
        $character = new Character();
        $character->setName('Test Character');

        $user->addCharacter($character);

        $this->assertCount(1, $user->getCharacters());
        $this->assertSame($user, $character->getUser());
    }

    public function testRemoveCharacter(): void
    {
        $user = new User();
        $character = new Character();

        $user->addCharacter($character);
        $user->removeCharacter($character);

        $this->assertCount(0, $user->getCharacters());
    }

    public function testSetMainCharacter(): void
    {
        $user = new User();
        $character = new Character();
        $character->setName('Main Character');
        $character->setCorporationId(12345);
        $character->setCorporationName('Test Corp');

        $user->addCharacter($character);
        $user->setMainCharacter($character);

        $this->assertSame($character, $user->getMainCharacter());
        $this->assertSame(12345, $user->getCorporationId());
        $this->assertSame('Test Corp', $user->getCorporationName());
    }

    public function testUpdateLastLogin(): void
    {
        $user = new User();

        $this->assertNull($user->getLastLoginAt());

        $user->updateLastLogin();

        $this->assertNotNull($user->getLastLoginAt());
        $this->assertEqualsWithDelta(time(), $user->getLastLoginAt()->getTimestamp(), 2);
    }

    public function testUserRoles(): void
    {
        $user = new User();

        $this->assertContains('ROLE_USER', $user->getRoles());
    }
}
