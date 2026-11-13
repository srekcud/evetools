<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
#[Group('issue-880')]
class UserEffectiveMarketStructureTest extends TestCase
{
    private const CJ6MT_ID = 1049588174021;
    private const HWWF_ID = 1035466617946;
    private const UNFOLLOWED_ID = 1022734985679;

    public function testPreferredStructureWinsOverTheMarketStructuresList(): void
    {
        $user = $this->userWithMarketStructures();
        $user->setPreferredMarketStructureId(self::HWWF_ID);
        $user->setPreferredMarketStructureName('4-HWWF preferred');

        self::assertSame(self::HWWF_ID, $user->getEffectiveMarketStructureId());
        self::assertSame('4-HWWF preferred', $user->getEffectiveMarketStructureName());
    }

    public function testFirstMarketStructureOfTheListIsEffectiveWhenNoPreferredStructureIsSet(): void
    {
        $user = $this->userWithMarketStructures();

        self::assertSame(self::CJ6MT_ID, $user->getEffectiveMarketStructureId());
        self::assertSame('C-J6MT', $user->getEffectiveMarketStructureName());
    }

    public function testEffectiveNameIsNullWhenThePreferredStructureHasNoNameEvenIfTheListIsNotEmpty(): void
    {
        $user = $this->userWithMarketStructures();
        $user->setPreferredMarketStructureId(self::HWWF_ID);

        self::assertSame(self::HWWF_ID, $user->getEffectiveMarketStructureId());
        self::assertNull($user->getEffectiveMarketStructureName());
    }

    public function testNoEffectiveMarketStructureWhenNeitherPreferredNorListIsSet(): void
    {
        $user = new User();

        self::assertNull($user->getEffectiveMarketStructureId());
        self::assertNull($user->getEffectiveMarketStructureName());
    }

    public function testFollowsMarketStructureWhenItIsThePreferredStructure(): void
    {
        $user = new User();
        $user->setPreferredMarketStructureId(self::CJ6MT_ID);

        self::assertTrue($user->followsMarketStructure(self::CJ6MT_ID));
        self::assertFalse($user->followsMarketStructure(self::UNFOLLOWED_ID));
    }

    public function testFollowsMarketStructureWhenItIsInTheMarketStructuresList(): void
    {
        $user = $this->userWithMarketStructures();

        self::assertTrue($user->followsMarketStructure(self::CJ6MT_ID));
        self::assertTrue($user->followsMarketStructure(self::HWWF_ID));
        self::assertFalse($user->followsMarketStructure(self::UNFOLLOWED_ID));
    }

    public function testFollowsNoMarketStructureWhenNeitherPreferredNorListIsSet(): void
    {
        self::assertFalse((new User())->followsMarketStructure(self::CJ6MT_ID));
    }

    private function userWithMarketStructures(): User
    {
        $user = new User();
        $user->setMarketStructures([
            ['id' => self::CJ6MT_ID, 'name' => 'C-J6MT'],
            ['id' => self::HWWF_ID, 'name' => '4-HWWF'],
        ]);

        return $user;
    }
}
