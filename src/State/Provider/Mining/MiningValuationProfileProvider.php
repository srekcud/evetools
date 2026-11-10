<?php

declare(strict_types=1);

namespace App\State\Provider\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mining\MiningValuationProfileResource;
use App\Service\MiningValuation\MiningValuationProfileReader;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningValuationProfileResource>
 */
final readonly class MiningValuationProfileProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningValuationProfileReader $profileReader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningValuationProfileResource
    {
        return new MiningValuationProfileResource();
    }
}
