<?php

declare(strict_types=1);

namespace App\Service\Industry\ContractSales;

use App\Constant\EveConstants;
use App\Repository\CachedStructureRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\UserRepository;

class PublicContractRegions
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly CachedStructureRepository $cachedStructureRepository,
        private readonly MapSolarSystemRepository $solarSystemRepository,
    ) {
    }

    /**
     * @return array{regionIds: list<int>, unknownStructureIds: list<int>}
     */
    public function resolve(): array
    {
        $regionIds = [EveConstants::THE_FORGE_REGION_ID => EveConstants::THE_FORGE_REGION_ID];
        $unknownStructureIds = [];

        foreach ($this->userRepository->findDistinctPreferredMarketStructureIds() as $structureId) {
            $regionId = $this->regionIdOf($structureId);
            if (null === $regionId) {
                $unknownStructureIds[] = $structureId;
                continue;
            }
            $regionIds[$regionId] = $regionId;
        }

        return ['regionIds' => array_values($regionIds), 'unknownStructureIds' => $unknownStructureIds];
    }

    private function regionIdOf(int $structureId): ?int
    {
        $solarSystemId = $this->cachedStructureRepository->findByStructureId($structureId)?->getSolarSystemId();
        if (null === $solarSystemId) {
            return null;
        }

        return $this->solarSystemRepository->find($solarSystemId)?->getRegionId();
    }
}
