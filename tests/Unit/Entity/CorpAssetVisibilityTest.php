<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\CorpAssetVisibility;
use App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CorpAssetVisibility::class)]
#[Group('issue-358')]
final class CorpAssetVisibilityTest extends TestCase
{
    private const string ALICE_ID = '0199a1f0-0000-7000-8000-000000358001';
    private const string BOB_ID = '0199a1f0-0000-7000-8000-000000358002';
    private const int ALL_MEMBERS_DIVISION = 2;
    private const int ALICE_ONLY_DIVISION = 1;
    private const int MASKED_DIVISION = 5;

    private User $alice;
    private User $bob;
    private CorpAssetVisibility $visibility;

    protected function setUp(): void
    {
        $this->alice = $this->userWithId(self::ALICE_ID);
        $this->bob = $this->userWithId(self::BOB_ID);
        $this->visibility = (new CorpAssetVisibility())
            ->setCorporationId(98_358_001)
            ->setVisibleDivisions([self::ALL_MEMBERS_DIVISION])
            ->setRestrictedDivisions([self::ALICE_ONLY_DIVISION => [self::ALICE_ID]]);
    }

    public function testListedPlayerSeesTheDivisionOfTheListAndTheDivisionsOfAllMembersSorted(): void
    {
        self::assertSame([1, 2], $this->visibility->divisionsVisibleTo($this->alice));
    }

    public function testPlayerNotListedSeesOnlyTheDivisionsOfAllMembers(): void
    {
        self::assertSame([2], $this->visibility->divisionsVisibleTo($this->bob));
    }

    public function testWithoutViewerOnlyTheDivisionsOfAllMembersAreVisible(): void
    {
        self::assertSame([2], $this->visibility->divisionsVisibleTo(null));
    }

    public function testDivisionInNeitherListIsMaskedForEveryoneIncludingAListedPlayer(): void
    {
        self::assertFalse($this->visibility->isVisibleTo(self::MASKED_DIVISION, $this->alice));
        self::assertFalse($this->visibility->isVisibleTo(self::MASKED_DIVISION, null));
        self::assertTrue($this->visibility->isVisibleTo(self::ALICE_ONLY_DIVISION, $this->alice));
        self::assertFalse($this->visibility->isVisibleTo(self::ALICE_ONLY_DIVISION, $this->bob));
    }

    private function userWithId(string $userId): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::fromString($userId));

        return $user;
    }
}
