<?php

declare(strict_types=1);

namespace App\State\Provider\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mining\FollowedRefineryResource;
use App\Repository\MoonRefineryFollowRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<FollowedRefineryResource>
 */
final readonly class FollowedRefineryProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MoonRefineryFollowRepository $followRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): FollowedRefineryResource
    {
        throw new \LogicException('Not implemented');
    }
}
