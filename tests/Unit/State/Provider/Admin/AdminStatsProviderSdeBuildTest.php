<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Admin;

use ApiPlatform\Metadata\Get;
use App\Entity\Character;
use App\Entity\SdeBuildState;
use App\Entity\User;
use App\Repository\SdeBuildStateRepository;
use App\Security\AdminCharacterLister;
use App\Security\AdminChecker;
use App\Service\Admin\AdminService;
use App\Service\Admin\CharacterRepairLister;
use App\Service\ESI\EsiErrorBudget;
use App\Service\Mercure\MercureCookieSizeMonitor;
use App\Service\Sde\SdeBuildStatusResolver;
use App\State\Provider\Admin\AdminStatsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(AdminStatsProvider::class)]
#[Group('issue-848')]
final class AdminStatsProviderSdeBuildTest extends TestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 2_113_848_001;

    public function testStatsExposeTheImportedBuildAndTheLatestCcpBuild(): void
    {
        $state = (new SdeBuildState())
            ->setImportedBuildNumber(3213160)
            ->setImportedReleaseDate(new \DateTimeImmutable('2026-06-02T10:00:00Z'))
            ->setImportedAt(new \DateTimeImmutable('2026-06-03T08:15:00Z'))
            ->setLatestBuildNumber(3693310)
            ->setLatestReleaseDate(new \DateTimeImmutable('2026-11-12T11:07:00Z'))
            ->setLatestCheckedAt(new \DateTimeImmutable('2026-11-13T15:30:00Z'));

        $block = $this->provider($state)->provide(new Get())->sdeBuild;

        self::assertSame('outdated', $block->status);
        self::assertSame(3213160, $block->importedBuildNumber);
        self::assertSame('2026-06-02T10:00:00+00:00', $block->importedReleaseDate);
        self::assertSame('2026-06-03T08:15:00+00:00', $block->importedAt);
        self::assertSame(3693310, $block->latestBuildNumber);
        self::assertSame('2026-11-12T11:07:00+00:00', $block->latestReleaseDate);
        self::assertSame('2026-11-13T15:30:00+00:00', $block->latestCheckedAt);
    }

    public function testStatsExposeNotRecordedWhenNoBuildWasRecordedAtImport(): void
    {
        $state = (new SdeBuildState())
            ->setLatestBuildNumber(3693310)
            ->setLatestReleaseDate(new \DateTimeImmutable('2026-11-12T11:07:00Z'))
            ->setLatestCheckedAt(new \DateTimeImmutable('2026-11-13T15:30:00Z'));

        $block = $this->provider($state)->provide(new Get())->sdeBuild;

        self::assertSame('not_recorded', $block->status);
        self::assertNull($block->importedBuildNumber);
        self::assertNull($block->importedReleaseDate);
        self::assertNull($block->importedAt);
        self::assertSame(3693310, $block->latestBuildNumber);
    }

    public function testStatsExposeAnUnknownStatusBeforeAnyCheck(): void
    {
        $block = $this->provider(new SdeBuildState())->provide(new Get())->sdeBuild;

        self::assertSame('unknown', $block->status);
        self::assertNull($block->importedBuildNumber);
        self::assertNull($block->latestBuildNumber);
        self::assertNull($block->latestReleaseDate);
        self::assertNull($block->latestCheckedAt);
    }

    public function testStatsExposeUpToDateWhenTheImportedBuildIsTheLatestOne(): void
    {
        $state = (new SdeBuildState())
            ->setImportedBuildNumber(3693310)
            ->setLatestBuildNumber(3693310);

        self::assertSame('up_to_date', $this->provider($state)->provide(new Get())->sdeBuild->status);
    }

    private function provider(SdeBuildState $state): AdminStatsProvider
    {
        $character = (new Character())
            ->setEveCharacterId(self::ADMIN_EVE_CHARACTER_ID)
            ->setName('Admin Pilot');

        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($character);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $adminService = $this->createStub(AdminService::class);
        $adminService->method('getStats')->willReturn([]);

        $repository = $this->createStub(SdeBuildStateRepository::class);
        $repository->method('current')->willReturn($state);

        return new AdminStatsProvider(
            $security,
            $adminService,
            new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID),
            new EsiErrorBudget(new ArrayAdapter()),
            $this->createStub(MercureCookieSizeMonitor::class),
            $this->createStub(CharacterRepairLister::class),
            $this->createStub(AdminCharacterLister::class),
            $repository,
            new SdeBuildStatusResolver(),
        );
    }
}
