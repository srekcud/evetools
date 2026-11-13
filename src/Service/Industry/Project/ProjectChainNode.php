<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\User;

final readonly class ProjectChainNode
{
    /**
     * @param list<array{participantId: string, characterName: string|null, type: string, quantity: int}>                                                                          $suppliers
     * @param list<array{esiJobId: int, status: string, runs: int, characterName: string|null, endDate: string|null, cost: float|null, facilityName: string|null}> $jobs
     * @param list<string>                                                                                                                                                         $structures
     * @param array{name: string|null, status: string}|null                                                                                                                        $plannedStructure
     * @param array{seconds: int|null, missingData: list<array{reason: string, typeId: int|null}>, characterName: string|null}|null $plannedCopyDuration
     * @param array{state: string, runs: int}|null $mark
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
        public ?int $level,
        public ?array $plannedCopyDuration = null,
        public ?array $mark = null,
    ) {
    }

    /**
     * @param list<array{esiJobId: int, status: string, runs: int, characterName: string|null, endDate: string|null, cost: float|null, facilityName: string|null}> $jobs
     */
    public function withJobs(array $jobs): self
    {
        return clone($this, ['jobs' => $jobs]);
    }

    private static function sameUser(User $first, User $second): bool
    {
        $firstId = $first->getId();
        $secondId = $second->getId();

        return null !== $firstId && null !== $secondId && $firstId->equals($secondId);
    }

    public function withPlannedCopyDurationSeenBy(User $owner, User $viewer): self
    {
        if (null === $this->plannedCopyDuration || null === $this->plannedCopyDuration['characterName'] || self::sameUser($owner, $viewer)) {
            return $this;
        }

        return clone($this, ['plannedCopyDuration' => [...$this->plannedCopyDuration, 'characterName' => PilotName::of($owner)]]);
    }
}
