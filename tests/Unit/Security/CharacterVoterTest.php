<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Character;
use App\Entity\User;
use App\Security\Voter\CharacterVoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[CoversClass(CharacterVoter::class)]
class CharacterVoterTest extends TestCase
{
    private CharacterVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new CharacterVoter();
    }

    public function testVoteGrantsViewForOwnCharacter(): void
    {
        $user = new User();
        $character = new Character();
        $user->addCharacter($character);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $this->voter->vote($token, $character, [CharacterVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteDeniesViewForOtherUserCharacter(): void
    {
        $user1 = new User();
        $user2 = new User();
        $character = new Character();
        $user2->addCharacter($character);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user1);

        $result = $this->voter->vote($token, $character, [CharacterVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoteDeniesDeleteForMainCharacter(): void
    {
        $user = new User();
        $character = new Character();
        $user->addCharacter($character);
        $user->setMainCharacter($character);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $this->voter->vote($token, $character, [CharacterVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoteGrantsDeleteForAltCharacter(): void
    {
        $user = new User();
        $mainCharacter = new Character();
        $altCharacter = new Character();

        $user->addCharacter($mainCharacter);
        $user->addCharacter($altCharacter);
        $user->setMainCharacter($mainCharacter);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $this->voter->vote($token, $altCharacter, [CharacterVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteAbstainsForNonCharacterSubject(): void
    {
        $user = new User();

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $this->voter->vote($token, new \stdClass(), [CharacterVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
