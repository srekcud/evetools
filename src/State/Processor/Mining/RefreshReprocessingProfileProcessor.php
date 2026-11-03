<?php

declare(strict_types=1);

namespace App\State\Processor\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mining\ReprocessingProfileResource;
use App\Entity\User;
use App\Repository\ReprocessingProfileRepository;
use App\Service\Mining\ReprocessingProfileRecalculator;
use App\State\Provider\Mining\ReprocessingProfileResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, ReprocessingProfileResource>
 */
final readonly class RefreshReprocessingProfileProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private ReprocessingProfileRepository $profileRepository,
        private ReprocessingProfileRecalculator $recalculator,
        private ReprocessingProfileResourceMapper $resourceMapper,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ReprocessingProfileResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $profile = $this->profileRepository->findOneBy(['user' => $user])
            ?? throw new NotFoundHttpException('No reprocessing profile saved yet.');

        $this->recalculator->recalculate($profile);
        $this->entityManager->flush();

        return $this->resourceMapper->fromProfile($profile);
    }
}
