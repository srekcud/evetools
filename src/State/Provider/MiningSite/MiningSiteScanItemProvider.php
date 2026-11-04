<?php

declare(strict_types=1);

namespace App\State\Provider\MiningSite;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningSite\MiningSiteScanResource;
use App\Repository\MiningSiteRepository;
use App\Repository\MiningSiteScanRepository;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningSite\MiningSiteRequest;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningSiteScanResource>
 */
final readonly class MiningSiteScanItemProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationAccess $access,
        private MiningSiteRepository $siteRepository,
        private MiningSiteScanRepository $scanRepository,
        private MiningSiteResourceMapper $mapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningSiteScanResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $site = MiningSiteRequest::siteOf($this->siteRepository, $this->access, $user, $uriVariables['siteId']);

        return $this->mapper->scanToResource(MiningSiteRequest::scanOf($this->scanRepository, $site, $uriVariables['id']));
    }
}
