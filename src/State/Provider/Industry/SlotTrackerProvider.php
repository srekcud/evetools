<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\SlotTrackerResource;
use App\Entity\User;
use App\Service\Industry\SlotTrackerService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<SlotTrackerResource>
 */
class SlotTrackerProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly SlotTrackerService $slotTrackerService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SlotTrackerResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $status = $this->slotTrackerService->getSlotStatus($user);

        $resource = new SlotTrackerResource();
        $resource->globalKpis = $status['globalKpis'];
        $resource->characters = $status['characters'];
        $resource->timeline = $status['timeline'];
        $resource->skillsMayBeStale = $status['skillsMayBeStale'];

        return $resource;
    }
}
