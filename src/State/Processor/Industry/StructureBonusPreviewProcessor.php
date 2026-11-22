<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\StructureBonusPreviewResource;
use App\ApiResource\Input\Industry\StructureBonusPreviewInput;
use App\Industry\Infrastructure\Doctrine\StructureBonusSummary;

/**
 * The bonuses come from a structure that is never persisted, so the preview of the form and the
 * saved structure share the same calculation.
 *
 * @implements ProcessorInterface<StructureBonusPreviewInput, StructureBonusPreviewResource>
 */
class StructureBonusPreviewProcessor implements ProcessorInterface
{
    public function __construct(private readonly StructureBonusSummary $structureBonusSummary)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StructureBonusPreviewResource
    {
        assert($data instanceof StructureBonusPreviewInput);

        $bonuses = $this->structureBonusSummary->of($data->structureType, $data->securityType, $data->rigs);

        $preview = new StructureBonusPreviewResource();
        $preview->manufacturingMaterialBonus = $bonuses->manufacturingMaterialBonus;
        $preview->reactionMaterialBonus = $bonuses->reactionMaterialBonus;
        $preview->manufacturingTimeBonus = $bonuses->manufacturingTimeBonus;
        $preview->reactionTimeBonus = $bonuses->reactionTimeBonus;
        $preview->unrecognisedRigs = $bonuses->unrecognisedRigs;
        $preview->hullToSpecify = $bonuses->hullToSpecify;

        return $preview;
    }
}
