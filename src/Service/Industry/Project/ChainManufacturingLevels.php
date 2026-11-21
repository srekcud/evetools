<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;

final class ChainManufacturingLevels
{
    private const string GROUP_COMPONENT = 'component';
    private const string GROUP_FINAL = 'final';

    /**
     * @param array<IndustryProjectBomLine> $bomLines
     * @return list<int>
     */
    public static function jobTypeIdsOf(array $bomLines): array
    {
        $jobTypeIds = [];
        foreach ($bomLines as $line) {
            if (self::isLeveledJob($line)) {
                $jobTypeIds[$line->getTypeId()] = $line->getTypeId();
            }
        }

        return array_values($jobTypeIds);
    }

    /**
     * @param array<IndustryProjectBomLine> $bomLines
     * @param array<int, list<int>>         $producedIngredientsByTypeId recipe materials that have a producer, by product typeId
     * @return array<int, int> level by job product typeId, copy and invention lines left out
     */
    public static function byTypeId(array $bomLines, array $producedIngredientsByTypeId): array
    {
        $levels = [];
        foreach (self::jobTypeIdsOf($bomLines) as $typeId) {
            self::levelOf($typeId, $producedIngredientsByTypeId, $levels, []);
        }

        return array_intersect_key($levels, array_flip(self::jobTypeIdsOf($bomLines)));
    }

    private static function isLeveledJob(IndustryProjectBomLine $line): bool
    {
        return $line->isJob() && \in_array($line->getJobGroup(), [self::GROUP_COMPONENT, self::GROUP_FINAL], true);
    }

    /**
     * @param array<int, list<int>> $producedIngredientsByTypeId
     * @param array<int, int>       $levels
     * @param array<int, true>      $visiting
     */
    private static function levelOf(int $typeId, array $producedIngredientsByTypeId, array &$levels, array $visiting): int
    {
        if (isset($levels[$typeId])) {
            return $levels[$typeId];
        }
        if (isset($visiting[$typeId])) {
            throw new \UnexpectedValueException(\sprintf('The BOM has a manufacturing cycle through type %d.', $typeId));
        }
        $visiting[$typeId] = true;

        $ingredientTypeIds = $producedIngredientsByTypeId[$typeId]
            ?? throw new \UnexpectedValueException(\sprintf('The recipe of type %d is unknown.', $typeId));

        $highestIngredientLevel = 0;
        foreach ($ingredientTypeIds as $ingredientTypeId) {
            $highestIngredientLevel = max($highestIngredientLevel, self::levelOf($ingredientTypeId, $producedIngredientsByTypeId, $levels, $visiting));
        }

        return $levels[$typeId] = $highestIngredientLevel + 1;
    }
}
