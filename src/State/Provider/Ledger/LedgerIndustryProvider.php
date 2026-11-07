<?php

declare(strict_types=1);

namespace App\State\Provider\Ledger;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ledger\LedgerIndustryResource;
use App\Entity\User;
use App\Service\Industry\Revenue\IndustryRevenueReader;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<LedgerIndustryResource>
 */
class LedgerIndustryProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly IndustryRevenueReader $industryRevenueReader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): LedgerIndustryResource
    {
        if (!$this->security->getUser() instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        return new LedgerIndustryResource();
    }
}
