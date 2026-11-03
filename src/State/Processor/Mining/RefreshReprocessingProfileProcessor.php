<?php

declare(strict_types=1);

namespace App\State\Processor\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mining\ReprocessingProfileResource;
use App\Repository\ReprocessingProfileRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Mining\ReprocessingSkillLevelReader;
use App\Service\Mining\ReprocessingYieldCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<mixed, ReprocessingProfileResource>
 */
final readonly class RefreshReprocessingProfileProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private ReprocessingProfileRepository $profileRepository,
        private InvTypeRepository $invTypeRepository,
        private ReprocessingSkillLevelReader $skillLevelReader,
        private ReprocessingYieldCalculator $yieldCalculator,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ReprocessingProfileResource
    {
        return new ReprocessingProfileResource();
    }
}
