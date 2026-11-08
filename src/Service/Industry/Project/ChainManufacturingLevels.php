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
     * @return array<int, int> level by job product typeId, copy and invention lines left out
     */
    public static function byTypeId(array $bomLines): array
    {
        $jobTypeIds = [];
        $childTypeIdsByTypeId = [];
        foreach ($bomLines as $line) {
            if (!$line->isJob() || !\in_array($line->getJobGroup(), [self::GROUP_COMPONENT, self::GROUP_FINAL], true)) {
                continue;
            }
            $jobTypeIds[$line->getTypeId()] = true;
            $consumerTypeId = $line->getParentTypeId();
            if (self::GROUP_COMPONENT === $line->getJobGroup() && null !== $consumerTypeId) {
                $childTypeIdsByTypeId[$consumerTypeId][$line->getTypeId()] = true;
            }
        }

        $levels = [];
        foreach (array_keys($jobTypeIds) as $typeId) {
            self::levelOf($typeId, $childTypeIdsByTypeId, $levels, []);
        }

        return $levels;
    }

    /**
     * @param array<int, array<int, true>> $childTypeIdsByTypeId
     * @param array<int, int>              $levels
     * @param array<int, true>             $visiting
     */
    private static function levelOf(int $typeId, array $childTypeIdsByTypeId, array &$levels, array $visiting): int
    {
        if (isset($levels[$typeId])) {
            return $levels[$typeId];
        }
        if (isset($visiting[$typeId])) {
            throw new \UnexpectedValueException(\sprintf('The BOM has a manufacturing cycle through type %d.', $typeId));
        }
        $visiting[$typeId] = true;

        $highestChildLevel = 0;
        foreach (array_keys($childTypeIdsByTypeId[$typeId] ?? []) as $childTypeId) {
            $highestChildLevel = max($highestChildLevel, self::levelOf($childTypeId, $childTypeIdsByTypeId, $levels, $visiting));
        }

        return $levels[$typeId] = $highestChildLevel + 1;
    }
}
