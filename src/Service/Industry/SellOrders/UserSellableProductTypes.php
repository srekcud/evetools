<?php

declare(strict_types=1);

namespace App\Service\Industry\SellOrders;

use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectParticipantRepository;

class UserSellableProductTypes
{
    public function __construct(
        private readonly IndustryProjectParticipantRepository $participantRepository,
        private readonly ProjectProductTypes $projectProductTypes,
    ) {
    }

    /**
     * @return list<int>
     */
    public function of(User $user): array
    {
        $typeIds = [];
        foreach ($this->participantRepository->findAcceptedWithProjectsByUser($user) as $membership) {
            $project = $membership->getProject();
            if ($project->isPersonalUse() || $project->getStatus() === ProjectStatus::Completed) {
                continue;
            }
            foreach (array_keys($this->projectProductTypes->of($project)) as $typeId) {
                $typeIds[$typeId] = $typeId;
            }
        }

        return array_values($typeIds);
    }
}
