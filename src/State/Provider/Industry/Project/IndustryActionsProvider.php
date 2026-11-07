<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\IndustryActionResource;
use App\Repository\IndustryProjectParticipantRepository;
use App\Service\Industry\Project\ActionQueueSorter;
use App\Service\Industry\Project\ProjectCockpitCalculator;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<IndustryActionResource>
 */
class IndustryActionsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectParticipantRepository $memberRepository,
        private readonly ProjectCockpitInputFactory $inputFactory,
        private readonly ProjectCockpitCalculator $calculator,
        private readonly ActionQueueSorter $sorter,
    ) {
    }

    /** @return list<IndustryActionResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return [];
    }
}
