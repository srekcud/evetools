<?php

declare(strict_types=1);

namespace App\State\Processor\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Mining\FollowRefineryInput;
use App\ApiResource\Mining\FollowedRefineryResource;
use App\Repository\CorporationMiningExtractionRepository;
use App\Repository\MoonRefineryFollowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;

/**
 * @implements ProcessorInterface<FollowRefineryInput, FollowedRefineryResource>
 */
final readonly class FollowRefineryProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private CorporationMiningExtractionRepository $extractionRepository,
        private MoonRefineryFollowRepository $followRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FollowedRefineryResource
    {
        throw new \LogicException('Not implemented');
    }
}
