<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Dto\CharacterInfoDto;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Enum\AuthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
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

    #[Group('issue-211')]
    public function testUpdateAffiliationTakesCorporationAndAllianceRead(): void
    {
        $character = $this->characterOfOldCorp();

        $character->updateAffiliation(new CharacterInfoDto(12345, 'Renamed Industrialist', 98000002, 'New Corp', 99000001, 'New Alliance'));

        self::assertSame(
            [98000002, 'New Corp', 99000001, 'New Alliance'],
            [$character->getCorporationId(), $character->getCorporationName(), $character->getAllianceId(), $character->getAllianceName()],
        );
    }

    #[Group('issue-211')]
    public function testUpdateAffiliationWithoutAllianceClearsTheRecordedAlliance(): void
    {
        $character = $this->characterOfOldCorp()->setAllianceId(99000001)->setAllianceName('Old Alliance');

        $character->updateAffiliation(new CharacterInfoDto(12345, 'Spec Industrialist', 98000002, 'New Corp'));

        self::assertSame(
            [98000002, 'New Corp', null, null],
            [$character->getCorporationId(), $character->getCorporationName(), $character->getAllianceId(), $character->getAllianceName()],
        );
    }

    #[Group('issue-211')]
    public function testUpdateAffiliationKeepsTheNameAndEveCharacterIdOfTheCharacter(): void
    {
        $character = $this->characterOfOldCorp();

        $updatedCharacter = $character->updateAffiliation(new CharacterInfoDto(67890, 'Renamed Industrialist', 98000002, 'New Corp'));

        self::assertSame($character, $updatedCharacter);
        self::assertSame([12345, 'Spec Industrialist'], [$character->getEveCharacterId(), $character->getName()]);
    }

    public function testNewCharacterHasAValidAuthStatus(): void
    {
        $character = new Character();

        self::assertSame(AuthStatus::Valid, $character->getAuthStatus());
        self::assertTrue($character->isAuthValid());
    }

    public function testMarkAuthInvalidMakesTheCharacterAuthInvalid(): void
    {
        $character = (new Character())->markAuthInvalid();

        self::assertSame(AuthStatus::Invalid, $character->getAuthStatus());
        self::assertFalse($character->isAuthValid());
    }

    public function testMarkAuthValidRestoresAnInvalidCharacterAuth(): void
    {
        $character = (new Character())->markAuthInvalid()->markAuthValid();

        self::assertSame(AuthStatus::Valid, $character->getAuthStatus());
        self::assertTrue($character->isAuthValid());
    }

    public function testRevokingTheAuthOfAnAltLeavesTheMainCharacterValid(): void
    {
        $user = new User();
        $mainCharacter = new Character();
        $altCharacter = new Character();
        $user->addCharacter($mainCharacter);
        $user->addCharacter($altCharacter);
        $user->setMainCharacter($mainCharacter);

        $altCharacter->markAuthInvalid();

        self::assertTrue($mainCharacter->isAuthValid());
        self::assertFalse($altCharacter->isAuthValid());
    }

    private function characterOfOldCorp(): Character
    {
        return (new Character())
            ->setEveCharacterId(12345)
            ->setName('Spec Industrialist')
            ->setCorporationId(98000001)
            ->setCorporationName('Old Corp');
    }
}
