<?php

declare(strict_types=1);

namespace App\State\Processor\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Mining\FollowRefineryInput;
use App\ApiResource\Mining\FollowedRefineryResource;
use App\Entity\MoonRefineryFollow;
use App\Repository\CorporationMiningExtractionRepository;
use App\Repository\MoonRefineryFollowRepository;
use App\Service\MiningOperation\MiningOperationRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

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
        $user = MiningOperationRequest::userOf($this->security);

        \assert($data instanceof FollowRefineryInput);
        $structureId = $data->structureId ?? throw new BadRequestHttpException('structureId is required');

        $corporationId = $user->getCorporationId();
        if ($corporationId === null || !$this->extractionRepository->existsForRefinery($corporationId, $structureId)) {
            throw new BadRequestHttpException('This refinery has no moon extraction in your corporation');
        }

        $follow = $this->followRepository->findOneBy(['user' => $user, 'structureId' => $structureId]);
        if ($follow === null) {
            $follow = (new MoonRefineryFollow())
                ->setUser($user)
                ->setCorporationId($corporationId)
                ->setStructureId($structureId)
                ->setCreatedAt($this->clock->now());
            $this->entityManager->persist($follow);
            $this->entityManager->flush();
        }

        return FollowedRefineryResource::fromFollow($follow);
    }
}
