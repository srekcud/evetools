<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\User;
use App\Repository\CachedIndustryJobRepository;
use App\Service\Industry\Project\ProjectJobsSyncReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectJobsSyncReader::class)]
#[Group('issue-1200')]
final class ProjectJobsSyncReaderTest extends TestCase
{
    private const int ARIA_MAIN_ID = 2_112_000_101;
    private const int ARIA_ALT_ID = 2_112_000_102;
    private const int BRANN_MAIN_ID = 2_112_000_201;

    private array $cachedAtByEveCharacterId = [];

    public function testAnInstallerIsDatedByItsOldestCharacterWhenEachCharacterIsDatedByItsNewestCachedJob(): void
    {
        $aria = $this->pilot('Aria Solberg', [self::ARIA_MAIN_ID, self::ARIA_ALT_ID]);
        $this->cachedAtByEveCharacterId = [
            self::ARIA_MAIN_ID => new \DateTimeImmutable('2026-11-21T10:20:00+00:00'),
            self::ARIA_ALT_ID => new \DateTimeImmutable('2026-11-21T10:00:00+00:00'),
        ];

        $installers = $this->read([$aria], $aria);

        self::assertSame(
            (new \DateTimeImmutable('2026-11-21T10:00:00+00:00'))->format(\DateTimeInterface::ATOM),
            $installers[0]['lastSyncedAt'],
        );
    }

    public function testAnInstallerWithoutAnyCachedJobHasAnUnknownLastSyncInsteadOfADefaultDate(): void
    {
        $aria = $this->pilot('Aria Solberg', [self::ARIA_MAIN_ID]);
        $brann = $this->pilot('Brann Holt', [self::BRANN_MAIN_ID]);
        $this->cachedAtByEveCharacterId = [self::ARIA_MAIN_ID => new \DateTimeImmutable('2026-11-21T10:00:00+00:00')];

        $installers = $this->read([$aria, $brann], $aria);

        self::assertSame(
            [['Aria Solberg', true, '2026-11-21T10:00:00+00:00'], ['Brann Holt', false, null]],
            array_map(static fn (array $installer): array => [$installer['name'], $installer['isViewer'], $installer['lastSyncedAt']], $installers),
        );
    }

    public function testAnInstallerWithoutMainCharacterIsNamedUnknown(): void
    {
        $viewer = $this->pilot('Aria Solberg', [self::ARIA_MAIN_ID]);

        $installers = $this->read([new User()], $viewer);

        self::assertSame('Unknown', $installers[0]['name']);
    }

    public function testTheInstallersKeepTheOrderOfTheGivenUsers(): void
    {
        $aria = $this->pilot('Aria Solberg', [self::ARIA_MAIN_ID]);
        $brann = $this->pilot('Brann Holt', [self::BRANN_MAIN_ID]);

        $installers = $this->read([$brann, $aria], $aria);

        self::assertSame(['Brann Holt', 'Aria Solberg'], array_column($installers, 'name'));
    }

    private function read(array $installers, User $viewer): array
    {
        $jobRepository = $this->createStub(CachedIndustryJobRepository::class);
        $jobRepository->method('newestCachedAtByEveCharacterId')->willReturn($this->cachedAtByEveCharacterId);

        return (new ProjectJobsSyncReader($jobRepository))->of($installers, $viewer);
    }

    private function pilot(string $mainName, array $eveCharacterIds): User
    {
        $user = new User();
        foreach ($eveCharacterIds as $index => $eveCharacterId) {
            $character = (new Character())
                ->setName(0 === $index ? $mainName : $mainName.' alt')
                ->setEveCharacterId($eveCharacterId);
            $user->addCharacter($character);
            if (0 === $index) {
                $user->setMainCharacter($character);
            }
        }

        return $user;
    }
}
