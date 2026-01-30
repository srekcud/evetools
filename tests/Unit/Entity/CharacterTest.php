<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Character::class)]
class CharacterTest extends TestCase
{
    public function testSetEveCharacterId(): void
    {
        $character = new Character();
        $character->setEveCharacterId(123456789);

        $this->assertSame(123456789, $character->getEveCharacterId());
    }

    public function testIsMain(): void
    {
        $user = new User();
        $mainCharacter = new Character();
        $altCharacter = new Character();

        $user->addCharacter($mainCharacter);
        $user->addCharacter($altCharacter);
        $user->setMainCharacter($mainCharacter);

        $this->assertTrue($mainCharacter->isMain());
        $this->assertFalse($altCharacter->isMain());
    }

    public function testIsInSameCorporation(): void
    {
        $character1 = new Character();
        $character1->setCorporationId(12345);

        $character2 = new Character();
        $character2->setCorporationId(12345);

        $character3 = new Character();
        $character3->setCorporationId(99999);

        $this->assertTrue($character1->isInSameCorporation($character2));
        $this->assertFalse($character1->isInSameCorporation($character3));
    }

    public function testSetEveToken(): void
    {
        $character = new Character();
        $token = new EveToken();

        $character->setEveToken($token);

        $this->assertSame($token, $character->getEveToken());
        $this->assertSame($character, $token->getCharacter());
    }

    public function testUpdateLastSync(): void
    {
        $character = new Character();

        $this->assertNull($character->getLastSyncAt());

        $character->updateLastSync();

        $this->assertNotNull($character->getLastSyncAt());
    }

    public function testAllianceInfo(): void
    {
        $character = new Character();

        $this->assertNull($character->getAllianceId());
        $this->assertNull($character->getAllianceName());

        $character->setAllianceId(99000001);
        $character->setAllianceName('Test Alliance');

        $this->assertSame(99000001, $character->getAllianceId());
        $this->assertSame('Test Alliance', $character->getAllianceName());
    }
}
