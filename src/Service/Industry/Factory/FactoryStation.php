<?php

declare(strict_types=1);

namespace App\Service\Industry\Factory;

final readonly class FactoryStation
{
    /**
     * @param list<string>                $specialisations
     * @param list<FactoryStationAccess>  $access
     * @param list<FactoryStationProject> $projects
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $structureType,
        public string $securityType,
        public ?int $locationId,
        public ?int $solarSystemId,
        public ?string $solarSystemName,
        public array $specialisations,
        public ?float $manufacturingCostIndex,
        public ?float $reactionCostIndex,
        public array $access,
        public ?int $linesInUse,
        public array $projects,
    ) {
    }
}
