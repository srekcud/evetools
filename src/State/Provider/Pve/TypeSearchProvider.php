<?php

declare(strict_types=1);

namespace App\State\Provider\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Pve\TypeSearchResource;
use App\Entity\User;
use App\Repository\Sde\InvTypeRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<TypeSearchResource[]>
 */
class TypeSearchProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $query = $request?->query->get('query', '') ?? '';

        if (strlen($query) < 2) {
            return [];
        }

        $types = $this->invTypeRepository->searchByName($query, 20);

        return array_map(function ($t) {
            $resource = new TypeSearchResource();
            $resource->typeId = $t->getTypeId();
            $resource->typeName = $t->getTypeName();

            return $resource;
        }, $types);
    }
}
