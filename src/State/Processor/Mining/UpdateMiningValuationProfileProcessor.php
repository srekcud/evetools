<?php

declare(strict_types=1);

namespace App\State\Processor\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Mining\MiningValuationProfileInput;
use App\ApiResource\Mining\MiningValuationProfileResource;
use App\Repository\MiningValuationProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<MiningValuationProfileInput, MiningValuationProfileResource>
 */
final readonly class UpdateMiningValuationProfileProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningValuationProfileRepository $profileRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningValuationProfileResource
    {
        return new MiningValuationProfileResource();
    }
}
