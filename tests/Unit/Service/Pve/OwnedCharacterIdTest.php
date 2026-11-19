<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Pve;

use App\Entity\Character;
use App\Entity\User;
use App\Service\Pve\OwnedCharacterId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(OwnedCharacterId::class)]
#[Group('issue-1080')]
final class OwnedCharacterIdTest extends TestCase
{
    private const int MAIN_ID = 2112080001;
    private const int FOREIGN_ID = 2112080099;

    #[DataProvider('candidates')]
    public function testOnlyAnIdOfOneOfTheUsersCharactersIsKept(mixed $candidate, ?int $expected): void
    {
        $user = new User();
        $user->addCharacter((new Character())->setEveCharacterId(self::MAIN_ID)->setName('Main'));

        $this->assertSame($expected, OwnedCharacterId::of($user, $candidate));
    }

    /**
     * @return iterable<string, array{mixed, int|null}>
     */
    public static function candidates(): iterable
    {
        yield 'own character' => [self::MAIN_ID, self::MAIN_ID];
        yield 'another account character' => [self::FOREIGN_ID, null];
        yield 'missing' => [null, null];
        yield 'numeric string' => [(string) self::MAIN_ID, null];
        yield 'float' => [(float) self::MAIN_ID, null];
    }
}
