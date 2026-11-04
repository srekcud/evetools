<?php

declare(strict_types=1);

namespace App\State\Provider\MiningSite;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningSite\MiningSiteResource;
use App\Repository\MiningSiteRepository;
use App\Service\MiningOperation\MiningOperationRequest;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProviderInterface<MiningSiteResource>
 */
final readonly class MiningSiteCollectionProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private MiningSiteRepository $siteRepository,
        private MiningSiteResourceMapper $mapper,
    ) {
    }

    /**
     * @return list<MiningSiteResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = MiningOperationRequest::userOf($this->security);

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            throw new BadRequestHttpException('No current request.');
        }

        return array_map($this->mapper->siteToResource(...), $this->siteRepository->findOwnedBy($user, $this->solarSystemIdOf($request->query)));
    }

    /**
     * @param InputBag<string> $query
     */
    private function solarSystemIdOf(InputBag $query): ?int
    {
        if (!$query->has('solarSystemId')) {
            return null;
        }

        $solarSystemId = filter_var($query->get('solarSystemId'), FILTER_VALIDATE_INT);
        if ($solarSystemId === false || $solarSystemId <= 0) {
            throw new BadRequestHttpException('solarSystemId must be a positive integer.');
        }

        return $solarSystemId;
    }
}
