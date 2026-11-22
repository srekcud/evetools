<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StructureAccess;

use App\Entity\Character;
use App\Enum\StructureAccessStatus;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\CharacterStructureAccessRepository;

final readonly class CharacterStructureAccessReader implements CharacterStructureAccess
{
    public function __construct(
        private CharacterStructureAccessRepository $accessRepository,
        private CachedIndustryJobRepository $jobRepository,
    ) {
    }

    public function statusOf(Character $character, int $structureId): StructureAccessStatus
    {
        if (!StructureIds::isStructure($structureId)) {
            return StructureAccessStatus::Granted;
        }

        $row = $this->accessRepository->findOneByCharacterAndStructure($character, $structureId);
        $latestJobEnd = $this->jobRepository->latestEndDateAtStructure($character, $structureId);

        if (null === $row) {
            return null === $latestJobEnd ? StructureAccessStatus::Unknown : StructureAccessStatus::Granted;
        }

        if (StructureAccessStatus::Granted === $row->getStatus()) {
            return StructureAccessStatus::Granted;
        }

        return null !== $latestJobEnd && $latestJobEnd >= $row->getCheckedAt() ? StructureAccessStatus::Granted : StructureAccessStatus::Denied;
    }
}
