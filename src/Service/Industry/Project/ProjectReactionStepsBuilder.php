<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Recipe;

final readonly class ProjectReactionStepsBuilder
{
    private const string ACTIVITY_REACTION = 'reaction';

    /**
     * @param array<IndustryProjectBomLine> $bomLines
     * @param array<int, Recipe|null>       $recipesByTypeId
     * @param array<int, string>            $typeNames
     * @param list<int>                     $fuelBlockTypeIds
     * @return list<ProjectReactionStep>
     */
    public function build(array $bomLines, array $recipesByTypeId, array $typeNames, array $fuelBlockTypeIds): array
    {
        $steps = [];
        foreach ($this->reactionLinesByTypeId($bomLines) as $typeId => $lines) {
            $steps[] = $this->stepOf($typeId, $lines, $recipesByTypeId[$typeId] ?? null, $typeNames, $fuelBlockTypeIds);
        }

        usort($steps, static fn (ProjectReactionStep $left, ProjectReactionStep $right): int => $left->typeName <=> $right->typeName ?: $left->typeId <=> $right->typeId);

        return $steps;
    }

    /**
     * @param array<IndustryProjectBomLine> $bomLines
     * @return array<int, non-empty-list<IndustryProjectBomLine>>
     */
    private function reactionLinesByTypeId(array $bomLines): array
    {
        $linesByTypeId = [];
        foreach ($bomLines as $line) {
            if ($line->isJob() && !$line->isCoveredByStock() && self::ACTIVITY_REACTION === $line->getActivityType()) {
                $linesByTypeId[$line->getTypeId()][] = $line;
            }
        }

        return $linesByTypeId;
    }

    /**
     * @param non-empty-list<IndustryProjectBomLine> $lines
     * @param array<int, string>                     $typeNames
     * @param list<int>                              $fuelBlockTypeIds
     */
    private function stepOf(int $typeId, array $lines, ?Recipe $recipe, array $typeNames, array $fuelBlockTypeIds): ProjectReactionStep
    {
        $runs = 0;
        $requiredQuantity = 0;
        $surplusQuantity = 0;
        $surplusKnown = true;
        foreach ($lines as $line) {
            $runs += $line->getRuns() ?? throw new \UnexpectedValueException(\sprintf('Reaction line %s of type %d has no runs.', $line->getId()->toRfc4122(), $typeId));
            $requiredQuantity += $line->getRequiredQuantity();
            $lineSurplus = $line->getSurplusQuantity();
            if (null === $lineSurplus) {
                $surplusKnown = false;
            }
            $surplusQuantity = $lineSurplus ?? $surplusQuantity;
        }

        $surplus = $surplusKnown ? $surplusQuantity : null;
        $formula = null !== $recipe && ActivityKind::Reaction === $recipe->activity ? $recipe : null;
        $producedQuantity = null === $formula ? $requiredQuantity : $runs * $formula->outputPerRun->value;

        return new ProjectReactionStep(
            $typeId,
            $lines[0]->getTypeName(),
            $runs,
            $producedQuantity,
            $surplus,
            null === $surplus ? null : $producedQuantity - $surplus,
            $formula?->outputPerRun->value,
            $formula?->baseTimeSeconds,
            null === $formula ? null : $formula->baseTimeSeconds * $runs,
            null === $formula ? null : $this->inputsOf($formula, $runs, $typeNames, $fuelBlockTypeIds),
        );
    }

    /**
     * @param array<int, string> $typeNames
     * @param list<int>          $fuelBlockTypeIds
     * @return list<array{typeId: int, typeName: string|null, quantityPerRun: int, totalQuantity: int, isFuelBlock: bool}>
     */
    private function inputsOf(Recipe $formula, int $runs, array $typeNames, array $fuelBlockTypeIds): array
    {
        $inputs = [];
        foreach ($formula->materials as $material) {
            $inputs[] = [
                'typeId' => $material->typeId,
                'typeName' => $typeNames[$material->typeId] ?? null,
                'quantityPerRun' => $material->baseQuantityPerRun->value,
                'totalQuantity' => $material->baseQuantityPerRun->value * $runs,
                'isFuelBlock' => \in_array($material->typeId, $fuelBlockTypeIds, true),
            ];
        }

        usort($inputs, static fn (array $left, array $right): int => $left['isFuelBlock'] <=> $right['isFuelBlock']
            ?: ($left['typeName'] ?? '') <=> ($right['typeName'] ?? '')
            ?: $left['typeId'] <=> $right['typeId']);

        return $inputs;
    }
}
