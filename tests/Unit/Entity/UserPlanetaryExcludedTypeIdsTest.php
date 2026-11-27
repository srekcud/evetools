<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
#[Group('issue-1458')]
final class UserPlanetaryExcludedTypeIdsTest extends TestCase
{
    public function testTheExcludedTypeIdsAreSortedAndDeduplicatedWhenSetAndAnEmptyListRestoresEveryType(): void
    {
        $user = new User();
        $this->assertSame([], $user->getPlanetaryExcludedTypeIds());

        $user->setPlanetaryExcludedTypeIds([9832, 2393, 9832, 9848]);
        $this->assertSame([2393, 9832, 9848], $user->getPlanetaryExcludedTypeIds());

        $user->setPlanetaryExcludedTypeIds([]);
        $this->assertSame([], $user->getPlanetaryExcludedTypeIds());
    }
}
