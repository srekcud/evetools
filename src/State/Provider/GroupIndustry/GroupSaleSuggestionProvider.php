<?php

declare(strict_types=1);

namespace App\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GroupIndustry\GroupSaleSuggestionResource;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\GroupIndustryProjectRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<GroupSaleSuggestionResource>
 */
class GroupSaleSuggestionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly GroupIndustryProjectRepository $projectRepository,
        private readonly GroupProjectAccessChecker $accessChecker,
        private readonly CachedWalletTransactionRepository $transactionRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        throw new \LogicException('not implemented');
    }
}
