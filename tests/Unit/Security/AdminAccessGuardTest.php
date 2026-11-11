<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Character;
use App\Entity\User;
use App\Security\AdminAccessGuard;
use App\Security\AdminChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(AdminAccessGuard::class)]
#[Group('issue-647')]
final class AdminAccessGuardTest extends TestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 90000001;
    private const OTHER_EVE_CHARACTER_ID = 90000002;

    public function testAnAdminIsReturned(): void
    {
        $admin = $this->userWithMainCharacter(self::ADMIN_EVE_CHARACTER_ID);

        self::assertSame($admin, $this->guardFor($admin)->requireAdmin());
    }

    public function testAnAnonymousVisitorIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->guardFor(null)->requireAdmin();
    }

    public function testAUserWhoIsNotAnAdminIsForbidden(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->guardFor($this->userWithMainCharacter(self::OTHER_EVE_CHARACTER_ID))->requireAdmin();
    }

    private function guardFor(?User $user): AdminAccessGuard
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new AdminAccessGuard($security, new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID));
    }

    private function userWithMainCharacter(int $eveCharacterId): User
    {
        $character = (new Character())->setEveCharacterId($eveCharacterId)->setName('Pilot');
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);

        return $user;
    }
}
