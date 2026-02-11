<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ProjectStepResource;
use App\Entity\User;
use App\Repository\IndustryStepJobMatchRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<ProjectStepResource>
 */
class JobMatchDeleteProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryStepJobMatchRepository $matchRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectStepResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $match = $this->matchRepository->find(Uuid::fromString($uriVariables['id']));

        if ($match === null || $match->getStep()->getProject()->getUser() !== $user) {
            throw new NotFoundHttpException('Job match not found');
        }

        // Return a minimal resource (required by API Platform for DELETE)
        $resource = new ProjectStepResource();
        $resource->id = (string) $match->getId();

        return $resource;
    }
}
