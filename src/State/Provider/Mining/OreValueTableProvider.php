<?php

declare(strict_types=1);

namespace App\State\Provider\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mining\OreValueTableResource;
use App\Service\OreValueTableService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<OreValueTableResource>
 */
class OreValueTableProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly OreValueTableService $oreValueTableService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): OreValueTableResource
    {
        return new OreValueTableResource();
    }
}
