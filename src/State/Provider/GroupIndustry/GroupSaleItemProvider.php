<?php

declare(strict_types=1);

namespace App\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GroupIndustry\GroupIndustrySaleResource;
use App\Repository\GroupIndustryProjectRepository;
use App\Repository\GroupIndustrySaleRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<GroupIndustrySaleResource>
 */
class GroupSaleItemProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly GroupIndustryProjectRepository $projectRepository,
        private readonly GroupIndustrySaleRepository $saleRepository,
        private readonly GroupProjectAccessChecker $accessChecker,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GroupIndustrySaleResource
    {
        throw new \LogicException('not implemented');
    }
}
