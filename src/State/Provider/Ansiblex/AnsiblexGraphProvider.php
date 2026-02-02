<?php

declare(strict_types=1);

namespace App\State\Provider\Ansiblex;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ansiblex\AnsiblexGraphResource;
use App\Entity\User;
use App\Repository\AnsiblexJumpGateRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<AnsiblexGraphResource>
 */
class AnsiblexGraphProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AnsiblexJumpGateRepository $ansiblexRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AnsiblexGraphResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $graph = $this->ansiblexRepository->getAdjacencyList();

        $resource = new AnsiblexGraphResource();
        $resource->totalSystems = count($graph);
        $resource->graph = $graph;

        return $resource;
    }
}
