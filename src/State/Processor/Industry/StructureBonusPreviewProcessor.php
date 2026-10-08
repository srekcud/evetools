<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\StructureBonusPreviewResource;
use App\ApiResource\Input\Industry\StructureBonusPreviewInput;
use App\Entity\IndustryStructureConfig;

/**
 * The bonuses come from a structure that is never persisted, so the preview of the form and the
 * saved structure share the same calculation.
 *
 * @implements ProcessorInterface<StructureBonusPreviewInput, StructureBonusPreviewResource>
 */
class StructureBonusPreviewProcessor implements ProcessorInterface
{
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StructureBonusPreviewResource
    {
        assert($data instanceof StructureBonusPreviewInput);

        $structure = (new IndustryStructureConfig())
            ->setStructureType($data->structureType)
            ->setSecurityType($data->securityType)
            ->setRigs($data->rigs);

        $preview = new StructureBonusPreviewResource();
        $preview->manufacturingMaterialBonus = $structure->getManufacturingMaterialBonus();
        $preview->reactionMaterialBonus = $structure->getReactionMaterialBonus();
        $preview->manufacturingTimeBonus = $structure->getManufacturingTimeBonus();
        $preview->reactionTimeBonus = $structure->getReactionTimeBonus();

        return $preview;
    }
}
