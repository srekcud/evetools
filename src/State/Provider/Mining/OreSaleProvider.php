<?php

declare(strict_types=1);

namespace App\State\Provider\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mining\OreSaleResource;
use App\Entity\User;
use App\Service\Mining\OreSaleService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<OreSaleResource>
 */
final readonly class OreSaleProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private OreSaleService $oreSaleService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): OreSaleResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            throw new BadRequestHttpException('No current request.');
        }

        $structureId = $this->structureIdOf($request->query);
        $sale = $this->oreSaleService->build($user, $structureId);

        $resource = new OreSaleResource();
        $resource->structureId = $structureId;
        $resource->rows = $sale['rows'];
        $resource->totals = $sale['totals'];

        return $resource;
    }

    /**
     * @param InputBag<string> $query
     */
    private function structureIdOf(InputBag $query): ?int
    {
        if (!$query->has('structureId')) {
            return null;
        }

        $structureId = filter_var($query->get('structureId'), FILTER_VALIDATE_INT);
        if ($structureId === false || $structureId <= 0) {
            throw new BadRequestHttpException('structureId must be a positive integer.');
        }

        return $structureId;
    }
}
