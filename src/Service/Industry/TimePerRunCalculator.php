<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\Sde\IndustryActivityRepository;
use Doctrine\ORM\EntityManagerInterface;

class TimePerRunCalculator
{
    public function __construct(
        private readonly IndustryCalculationService $calculationService,
        private readonly IndustryActivityRepository $activityRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CachedCharacterSkillRepository $skillRepository,
    ) {
    }

    public function timePerRunAndRecommendedCharacterOf(User $user, int $blueprintTypeId, int $productTypeId, string $activityType, int $teLevel, ?IndustryStructureConfig $structureConfig, ?array $characterSkillLevels = null): array
    {
        throw new \LogicException('Not implemented yet (#283).');
    }

    public function calculateTimePerRun(IndustryProjectStep $step, ?array $characterSkills = null): ?int
    {
        throw new \LogicException('Not implemented yet (#283).');
    }

    public function timePerRunOf(User $user, int $blueprintTypeId, int $productTypeId, string $activityType, int $teLevel, ?IndustryStructureConfig $structureConfig, ?array $characterSkills): ?int
    {
        throw new \LogicException('Not implemented yet (#283).');
    }

    public function loadCharacterSkillLevels(User $user): array
    {
        throw new \LogicException('Not implemented yet (#283).');
    }

    public function bestCharacterForStepTime(User $user, int $blueprintTypeId, string $activityType, ?array $characterSkillLevels = null): array
    {
        throw new \LogicException('Not implemented yet (#283).');
    }

    public function calculateSkillTimeMultiplier(array $skillLevels, string $activityType, array $scienceSkillIds = []): float
    {
        throw new \LogicException('Not implemented yet (#283).');
    }

    public function getBlueprintScienceSkillIds(int $blueprintTypeId, string $activityType): array
    {
        throw new \LogicException('Not implemented yet (#283).');
    }
}
