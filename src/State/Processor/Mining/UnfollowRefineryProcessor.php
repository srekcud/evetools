<?php

declare(strict_types=1);

namespace App\State\Processor\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\MoonRefineryFollowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class UnfollowRefineryProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MoonRefineryFollowRepository $followRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        throw new \LogicException('Not implemented');
    }
}
