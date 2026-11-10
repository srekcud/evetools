<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Doctrine;

use App\Industry\Application\CharacterSkillLevels;
use App\Repository\CachedCharacterSkillRepository;
use Symfony\Contracts\Service\ResetInterface;

final class CachedCharacterSkillLevels implements CharacterSkillLevels, ResetInterface
{
    /** @var array<int, array<int, int>> */
    private array $levelsByEveCharacterId = [];

    public function __construct(private readonly CachedCharacterSkillRepository $skillRepository)
    {
    }

    public function levelOf(int $characterId, int $skillTypeId): ?int
    {
        $this->levelsByEveCharacterId[$characterId] ??= $this->skillRepository->findLevelsByEveCharacterId($characterId);

        return $this->levelsByEveCharacterId[$characterId][$skillTypeId] ?? null;
    }

    public function reset(): void
    {
        $this->levelsByEveCharacterId = [];
    }
}
