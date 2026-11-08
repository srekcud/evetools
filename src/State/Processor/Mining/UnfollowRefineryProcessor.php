<?php

declare(strict_types=1);

namespace App\State\Processor\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\MoonRefineryFollowRepository;
use App\Service\MiningOperation\MiningOperationRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        $user = MiningOperationRequest::userOf($this->security);

        $follow = $this->followRepository->findOneBy(['user' => $user, 'structureId' => (int) $uriVariables['structureId']])
            ?? throw new NotFoundHttpException('Refinery follow not found');

        $this->entityManager->remove($follow);
        $this->entityManager->flush();

        return null;
    }
}
