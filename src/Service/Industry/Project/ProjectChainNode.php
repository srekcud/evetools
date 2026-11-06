<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ProjectChainNode
{
    /**
     * @param list<array{participantId: string, characterName: string|null, type: string, quantity: int}>                                                                          $suppliers
     * @param list<array{esiJobId: int, status: string, runs: int, characterName: string|null, endDate: string|null, cost: float|null, facilityName: string|null}> $jobs
     * @param list<string>                                                                                                                                                         $structures
     * @param array{name: string|null, status: string}|null                                                                                                                        $plannedStructure
     */
    public function __construct(
        public string $id,
        public string $stage,
        public ?string $parentId,
        public ?int $typeId,
        public ?string $typeName,
        public ?string $activity,
        public ?int $runs,
        public ?int $meLevel,
        public ?int $teLevel,
        public ?int $requiredQuantity,
        public ?int $fulfilledQuantity,
        public string $status,
        public string $costState,
        public ?float $realCost,
        public array $suppliers,
        public array $jobs,
        public array $structures,
        public ?array $plannedStructure,
        public ?int $soldQuantity,
        public ?float $grossRevenue,
    ) {
    }
}
