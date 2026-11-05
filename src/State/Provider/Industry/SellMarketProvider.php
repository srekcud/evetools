<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\SellMarketResource;
use App\Service\Industry\SellMarkets\SellMarketComparison;
use App\Service\TypeNameResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<SellMarketResource>
 */
class SellMarketProvider implements ProviderInterface
{
    public const int MAXIMUM_QUANTITY = 100_000_000;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly SellMarketComparison $sellMarketComparison,
        private readonly TypeNameResolver $typeNameResolver,
        private readonly ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SellMarketResource
    {
        return new SellMarketResource();
    }
}
