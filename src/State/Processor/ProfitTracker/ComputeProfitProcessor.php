<?php

declare(strict_types=1);

namespace App\State\Processor\ProfitTracker;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ProfitTracker\ProfitComputeResource;
use App\Entity\User;
use App\Message\ComputeProfitMatches;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<mixed, ProfitComputeResource>
 */
class ComputeProfitProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $messageBus,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProfitComputeResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $userId = $user->getId()?->toRfc4122();
        if ($userId === null) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $days = (int) ($request?->query->get('days', 30) ?? 30);

        $this->messageBus->dispatch(new ComputeProfitMatches($userId, $days));

        $resource = new ProfitComputeResource();
        $resource->success = true;
        $resource->message = 'Profit matching computation dispatched';

        return $resource;
    }
}
