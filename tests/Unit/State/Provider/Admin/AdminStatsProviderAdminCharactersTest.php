<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Admin;

use ApiPlatform\Metadata\Get;
use App\Entity\Character;
use App\Entity\User;
use App\Security\AdminCharacterEntry;
use App\Repository\SdeBuildStateRepository;
use App\Security\AdminCharacterLister;
use App\Security\AdminChecker;
use App\Service\Admin\AdminService;
use App\Service\Admin\CharacterRepairLister;
use App\Service\ESI\EsiErrorBudget;
use App\Service\Mercure\MercureCookieSizeMonitor;
use App\Service\Sde\SdeBuildStatusResolver;
use App\State\Provider\Admin\AdminStatsProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[Group('issue-654')]
final class AdminStatsProviderAdminCharactersTest extends TestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 90000001;
    private const UNKNOWN_ADMIN_EVE_CHARACTER_ID = 90000002;

    public function testStatsExposeTheAdminCharactersWithTheirCountNameAndUserFlag(): void
    {
        $lister = $this->createStub(AdminCharacterLister::class);
        $lister->method('all')->willReturn([
            new AdminCharacterEntry(self::ADMIN_EVE_CHARACTER_ID, 'Mira Tolland', true),
            new AdminCharacterEntry(self::UNKNOWN_ADMIN_EVE_CHARACTER_ID, null, false),
        ]);

        $resource = $this->provider($lister)->provide(new Get());

        self::assertSame(2, $resource->adminCharacters->count);
        self::assertSame(self::ADMIN_EVE_CHARACTER_ID, $resource->adminCharacters->characters[0]->eveCharacterId);
        self::assertSame('Mira Tolland', $resource->adminCharacters->characters[0]->characterName);
        self::assertTrue($resource->adminCharacters->characters[0]->hasUser);
        self::assertSame(self::UNKNOWN_ADMIN_EVE_CHARACTER_ID, $resource->adminCharacters->characters[1]->eveCharacterId);
        self::assertNull($resource->adminCharacters->characters[1]->characterName);
        self::assertFalse($resource->adminCharacters->characters[1]->hasUser);
    }

    private function provider(AdminCharacterLister $lister): AdminStatsProvider
    {
        $adminService = $this->createStub(AdminService::class);
        $adminService->method('getStats')->willReturn([]);

        $character = (new Character())
            ->setEveCharacterId(self::ADMIN_EVE_CHARACTER_ID)
            ->setName('Admin Pilot');
        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($character);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new AdminStatsProvider(
            $security,
            $adminService,
            new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID),
            new EsiErrorBudget(new ArrayAdapter()),
            $this->createStub(MercureCookieSizeMonitor::class),
            $this->createStub(CharacterRepairLister::class),
            $lister,
            $this->createStub(SdeBuildStateRepository::class),
            new SdeBuildStatusResolver(),
        );
    }
}
