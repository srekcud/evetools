<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Entity\Character;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Admin\UserSearcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UserSearcher::class)]
#[Group('issue-647')]
final class UserSearcherTest extends TestCase
{
    private const USER_ID = '0192f0c4-6d2e-7a1b-9c3d-4e5f6a7b8c9d';

    public function testATermShorterThanTwoCharactersFindsNothingAndSkipsTheQuery(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('searchByCharacter');

        self::assertSame([], (new UserSearcher($users))->search(' a '));
    }

    public function testTheTrimmedTermAndTheLimitReachTheRepository(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('searchByCharacter')->with('mira', 20)->willReturn([]);

        (new UserSearcher($users))->search('  mira ');
    }

    public function testAHitDescribesTheUserThroughItsMainCharacter(): void
    {
        $main = (new Character())->setEveCharacterId(1001)->setName('Mira')->setCorporationId(1)->setCorporationName('C');
        $other = (new Character())->setEveCharacterId(1002)->setName('Amy')->setCorporationId(1)->setCorporationName('C');
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::fromString(self::USER_ID));
        $user->addCharacter($main);
        $user->addCharacter($other);
        $user->setMainCharacter($main);
        $user->setLastLoginAt(new \DateTimeImmutable('2026-11-01T10:00:00+00:00'));
        $users = $this->createStub(UserRepository::class);
        $users->method('searchByCharacter')->willReturn([$user]);

        $hits = (new UserSearcher($users))->search('mira');

        self::assertCount(1, $hits);
        self::assertSame(self::USER_ID, $hits[0]->userId);
        self::assertSame('Mira', $hits[0]->mainCharacterName);
        self::assertSame(1001, $hits[0]->mainEveCharacterId);
        self::assertSame(2, $hits[0]->characterCount);
        self::assertSame('2026-11-01T10:00:00+00:00', $hits[0]->lastLoginAt);
    }

    public function testAHitWithoutMainCharacterHasNullIdentity(): void
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::fromString(self::USER_ID));
        $users = $this->createStub(UserRepository::class);
        $users->method('searchByCharacter')->willReturn([$user]);

        $hit = (new UserSearcher($users))->search('mira')[0];

        self::assertNull($hit->mainCharacterName);
        self::assertNull($hit->mainEveCharacterId);
        self::assertSame(0, $hit->characterCount);
        self::assertNull($hit->lastLoginAt);
    }
}
