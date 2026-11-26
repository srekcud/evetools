<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Industry\Stock\PersonalStockReader;

final readonly class PlanetaryProjectNeedsReader
{
    public function __construct(
        private IndustryProjectRepository $projectRepository,
        private PlanetSchematicTypeRepository $schematicTypeRepository,
        private CachedAssetRepository $assetRepository,
        private PersonalStockReader $personalStockReader,
        private PlanetaryProjectNeedsBuilder $builder,
    ) {
    }

    /**
     * @return list<PlanetaryProjectNeed>
     */
    public function read(User $user): array
    {
        $demands = $this->demandsOf($user);
        if ([] === $demands) {
            return [];
        }

        return $this->builder->build($demands, $this->personalQuantitiesOf($user));
    }

    /**
     * @return list<ProjectLineDemand>
     */
    private function demandsOf(User $user): array
    {
        $projects = $this->projectRepository->findOpenOfAcceptedParticipant($user);
        if ([] === $projects) {
            return [];
        }

        $planetaryTypeIds = $this->planetaryTypeIds();
        $demands = [];
        foreach ($projects as $project) {
            foreach ($project->getBomItems() as $line) {
                if ($line->isJob() || !isset($planetaryTypeIds[$line->getTypeId()]) || $line->getRemainingQuantity() <= 0) {
                    continue;
                }
                $demands[] = new ProjectLineDemand(
                    $project->getId()->toRfc4122(),
                    $project->getName(),
                    $line->getTypeId(),
                    $line->getTypeName(),
                    $line->getRemainingQuantity(),
                );
            }
        }

        return $demands;
    }

    /**
     * @return array<int, true>
     */
    private function planetaryTypeIds(): array
    {
        $typeIds = [];
        foreach ($this->schematicTypeRepository->getSchematicMap() as $schematic) {
            if (null !== $schematic['output']) {
                $typeIds[$schematic['output']['typeId']] = true;
            }
        }

        return $typeIds;
    }

    /**
     * @return array<int, int>|null
     */
    private function personalQuantitiesOf(User $user): ?array
    {
        if (!$this->assetRepository->hasPersonalAssetsOf($user)) {
            return null;
        }

        $personalStock = $this->personalStockReader->of($user);

        return null === $personalStock->unknownReason() ? $personalStock->quantitiesByTypeId : null;
    }
}
