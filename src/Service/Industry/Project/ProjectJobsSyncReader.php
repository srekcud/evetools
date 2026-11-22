<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\User;
use App\Repository\CachedIndustryJobRepository;

final readonly class ProjectJobsSyncReader
{
    public function __construct(
        private CachedIndustryJobRepository $jobRepository,
    ) {
    }

    /**
     * @param list<User> $installers
     *
     * @return list<array{name: string, isViewer: bool, lastSyncedAt: string|null}>
     */
    public function of(array $installers, User $viewer): array
    {
        $characters = [];
        foreach ($installers as $installer) {
            foreach ($installer->getCharacters() as $character) {
                $characters[] = $character;
            }
        }
        $newestCachedAt = $this->jobRepository->newestCachedAtByEveCharacterId($characters);

        return array_map(
            static fn (User $installer): array => [
                'name' => PilotName::of($installer) ?? throw new \LogicException('An installer is always a user'),
                'isViewer' => $installer === $viewer,
                'lastSyncedAt' => self::oldestOf(array_map(
                    static fn (Character $character): ?\DateTimeImmutable => $newestCachedAt[$character->getEveCharacterId()] ?? null,
                    array_values($installer->getCharacters()->toArray()),
                ))?->format(\DateTimeInterface::ATOM),
            ],
            $installers,
        );
    }

    /**
     * @param list<\DateTimeImmutable|null> $dates
     */
    private static function oldestOf(array $dates): ?\DateTimeImmutable
    {
        $known = array_values(array_filter($dates, static fn (?\DateTimeImmutable $date): bool => null !== $date));

        return [] === $known ? null : min($known);
    }
}
