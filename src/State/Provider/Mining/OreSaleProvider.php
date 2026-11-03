<?php

declare(strict_types=1);

namespace App\State\Provider\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mining\OreSaleResource;
use App\Service\Mining\OreSaleService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

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
        return new OreSaleResource();
    }
}
