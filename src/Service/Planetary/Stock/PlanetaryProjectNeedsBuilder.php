<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

final readonly class PlanetaryProjectNeedsBuilder
{
    /**
     * @param list<ProjectLineDemand> $demands
     * @param array<int, int>|null $personalQuantities typeId => quantity, null when the stock is unknown
     *
     * @return list<PlanetaryProjectNeed>
     */
    public function build(array $demands, ?array $personalQuantities): array
    {
        $needs = [];
        foreach ($this->groupedByType($demands) as $typeId => $typeDemands) {
            $needs[] = $this->needOf($typeId, $typeDemands, $personalQuantities);
        }

        usort($needs, static fn (PlanetaryProjectNeed $left, PlanetaryProjectNeed $right): int => self::compareNullableLast($left->typeName, $right->typeName) ?: $left->typeId <=> $right->typeId);

        return $needs;
    }

    /**
     * @param list<ProjectLineDemand> $demands
     *
     * @return array<int, non-empty-list<ProjectLineDemand>>
     */
    private function groupedByType(array $demands): array
    {
        $grouped = [];
        foreach ($demands as $demand) {
            $grouped[$demand->typeId][] = $demand;
        }

        return $grouped;
    }

    /**
     * @param non-empty-list<ProjectLineDemand> $typeDemands
     * @param array<int, int>|null $personalQuantities
     */
    private function needOf(int $typeId, array $typeDemands, ?array $personalQuantities): PlanetaryProjectNeed
    {
        $required = array_sum(array_map(static fn (ProjectLineDemand $demand): int => $demand->remaining, $typeDemands));
        $stock = null === $personalQuantities ? null : (\array_key_exists($typeId, $personalQuantities) ? $personalQuantities[$typeId] : 0);

        return new PlanetaryProjectNeed(
            $typeId,
            $typeDemands[0]->typeName,
            $required,
            $stock,
            null === $stock ? null : max(0, $required - $stock),
            $this->projectsOf($typeDemands),
        );
    }

    /**
     * @param non-empty-list<ProjectLineDemand> $typeDemands
     *
     * @return list<array{projectId: string, projectName: string|null, remaining: int}>
     */
    private function projectsOf(array $typeDemands): array
    {
        $projects = [];
        foreach ($typeDemands as $demand) {
            if (!isset($projects[$demand->projectId])) {
                $projects[$demand->projectId] = ['projectId' => $demand->projectId, 'projectName' => $demand->projectName, 'remaining' => 0];
            }
            $projects[$demand->projectId]['remaining'] += $demand->remaining;
        }

        $projects = array_values($projects);
        usort($projects, static fn (array $left, array $right): int => self::compareNullableLast($left['projectName'], $right['projectName']) ?: strcmp($left['projectId'], $right['projectId']));

        return $projects;
    }

    private static function compareNullableLast(?string $left, ?string $right): int
    {
        if (null === $left || null === $right) {
            return (null === $left) <=> (null === $right);
        }

        return strcasecmp($left, $right);
    }
}
