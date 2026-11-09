<?php

declare(strict_types=1);

namespace App\State\Provider\MiningSite;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningSite\MiningSiteResource;
use App\Repository\MiningSiteRepository;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningSite\MiningSiteCompressionReport;
use App\Service\MiningSite\MiningSiteRequest;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningSiteResource>
 */
final readonly class MiningSiteItemProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationAccess $access,
        private MiningSiteRepository $siteRepository,
        private MiningSiteResourceMapper $mapper,
        private MiningSiteCompressionReport $compressionReport,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningSiteResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $site = MiningSiteRequest::siteOf($this->siteRepository, $this->access, $user, $uriVariables['id']);
        $resource = $this->mapper->siteToResource($site);
        $resource->compression = $this->compressionReport->of($site);

        return $resource;
    }
}
