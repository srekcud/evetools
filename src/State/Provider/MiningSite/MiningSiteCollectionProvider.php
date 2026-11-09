<?php

declare(strict_types=1);

namespace App\State\Provider\MiningSite;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningSite\MiningSiteResource;
use App\Entity\MiningSite;
use App\Repository\MiningOperationRepository;
use App\Repository\MiningSiteRepository;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningSite\MiningSiteCompressionReport;
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
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private RequestStack $requestStack,
        private MiningSiteRepository $siteRepository,
        private MiningSiteResourceMapper $mapper,
        private MiningSiteCompressionReport $compressionReport,
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

        $solarSystemId = $this->solarSystemIdOf($request->query);
        $sites = $request->query->has('operationId')
            ? $this->siteRepository->findOfOperation(
                MiningOperationRequest::participatedOperationOf($this->operationRepository, $this->access, $user, (string) $request->query->get('operationId')),
                $solarSystemId,
            )
            : $this->siteRepository->findOwnedBy($user, $solarSystemId);

        return array_map(function (MiningSite $site): MiningSiteResource {
            $resource = $this->mapper->siteToResource($site);
            $resource->compression = $this->compressionReport->of($site);

            return $resource;
        }, $sites);
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
