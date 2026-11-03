<?php

declare(strict_types=1);

namespace App\State\Provider\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mining\ReprocessingProfileResource;
use App\Repository\ReprocessingProfileRepository;
use App\Repository\Sde\InvTypeRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<ReprocessingProfileResource>
 */
final readonly class ReprocessingProfileProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private ReprocessingProfileRepository $profileRepository,
        private InvTypeRepository $invTypeRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ReprocessingProfileResource
    {
        return new ReprocessingProfileResource();
    }
}
