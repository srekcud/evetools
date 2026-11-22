<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\User;
use App\Industry\Application\ProductionStructure;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\MapSolarSystemRepository;

final readonly class OwnerStructureLabels
{
    public function __construct(
        private IndustryStructureConfigRepository $structureConfigRepository,
        private MapSolarSystemRepository $solarSystemRepository,
    ) {
    }

    /**
     * @return array<string, array{name: string, kind: string}> by structure id
     */
    public function of(User $owner): array
    {
        $labels = [];
        foreach ($this->structureConfigRepository->findByUser($owner) as $configuration) {
            $labels[$configuration->getId()->toRfc4122()] = ['name' => $configuration->getName(), 'kind' => $configuration->getStructureType()];
        }

        return $labels;
    }

    /**
     * @param list<ProductionStructure> $structures
     *
     * @return array<int, string> by solar system id
     */
    public function solarSystemNamesOf(array $structures): array
    {
        $names = [];
        foreach ($structures as $structure) {
            $solarSystemId = $structure->solarSystemId;
            if (null !== $solarSystemId && !\array_key_exists($solarSystemId, $names)) {
                $solarSystem = $this->solarSystemRepository->findBySolarSystemId($solarSystemId);
                if (null !== $solarSystem) {
                    $names[$solarSystemId] = $solarSystem->getSolarSystemName();
                }
            }
        }

        return $names;
    }
}
