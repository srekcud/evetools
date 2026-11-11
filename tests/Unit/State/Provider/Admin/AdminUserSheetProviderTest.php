<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Admin;

use ApiPlatform\Metadata\Get;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AdminAccessGuard;
use App\Security\AdminChecker;
use App\Service\Admin\UserLastDataReader;
use App\Service\Admin\UserSupportSheetBuilder;
use App\Service\ESI\AuthenticationService;
use App\State\Provider\Admin\AdminUserSheetProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(AdminUserSheetProvider::class)]
#[Group('issue-647')]
final class AdminUserSheetProviderTest extends TestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 90000001;
    private const TARGET_USER_ID = '0192f0c4-6d2e-7a1b-9c3d-4e5f6a7b8c9d';

    private ?User $targetUser;

    protected function setUp(): void
    {
        $this->targetUser = $this->userWithCharacter(1001, 'Mira');
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->targetUser, Uuid::fromString(self::TARGET_USER_ID));
    }

    public function testTheSheetResourceMapsTheCharactersAndSyncs(): void
    {
        $resource = $this->provide(self::ADMIN_EVE_CHARACTER_ID, self::TARGET_USER_ID);

        self::assertSame(self::TARGET_USER_ID, $resource->userId);
        self::assertSame('Mira', $resource->characters[0]->name);
        self::assertSame('valid', $resource->characters[0]->tokenState);
        self::assertSame('industry', $resource->syncs[0]->type);
        self::assertTrue($resource->syncs[0]->triggerable);
        self::assertNull($resource->syncs[0]->lastDataAt);
    }

    public function testAnUnknownUserIsNotFound(): void
    {
        $this->targetUser = null;

        $this->expectException(NotFoundHttpException::class);

        $this->provide(self::ADMIN_EVE_CHARACTER_ID, self::TARGET_USER_ID);
    }

    public function testAMalformedUserIdIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->provide(self::ADMIN_EVE_CHARACTER_ID, 'not-a-uuid');
    }

    public function testANonAdminIsForbiddenBeforeAnyLookup(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('find');

        $this->expectException(AccessDeniedHttpException::class);

        $this->provide(90000002, self::TARGET_USER_ID, $users);
    }

    private function provide(int $callerEveCharacterId, string $userId, ?UserRepository $users = null): \App\ApiResource\Admin\AdminUserSheetResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->userWithCharacter($callerEveCharacterId, 'Caller'));

        if ($users === null) {
            $users = $this->createStub(UserRepository::class);
            $users->method('find')->willReturnCallback(fn (): ?User => $this->targetUser);
        }

        $reader = $this->createStub(UserLastDataReader::class);
        $reader->method('lastDataByType')->willReturn([]);

        return (new AdminUserSheetProvider(
            new AdminAccessGuard($security, new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID)),
            $users,
            new UserSupportSheetBuilder($reader),
            new NullLogger(),
        ))->provide(new Get(), ['userId' => $userId]);
    }

    private function userWithCharacter(int $eveCharacterId, string $name): User
    {
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_000_001)
            ->setCorporationName('Test Corp');
        $character->setEveToken((new EveToken())
            ->setAccessToken('access')
            ->setRefreshTokenEncrypted('refresh')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes(AuthenticationService::getRequiredScopes()));
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);

        return $user;
    }
}
