<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StructureAccess;

use App\Entity\User;
use App\Enum\StructureAccessStatus;

final readonly class MemberStructureAccess
{
    public function __construct(private CharacterStructureAccessReader $reader)
    {
    }

    public function of(User $user, int $locationId): StructureAccessStatus
    {
        if (!StructureIds::isStructure($locationId)) {
            return StructureAccessStatus::Granted;
        }

        $statuses = [];
        foreach ($user->getCharacters() as $character) {
            $statuses[] = $this->reader->statusOf($character, $locationId);
        }

        if (\in_array(StructureAccessStatus::Granted, $statuses, true)) {
            return StructureAccessStatus::Granted;
        }

        if ([] !== $statuses && [StructureAccessStatus::Denied] === array_values(array_unique($statuses, SORT_REGULAR))) {
            return StructureAccessStatus::Denied;
        }

        return StructureAccessStatus::Unknown;
    }
}
