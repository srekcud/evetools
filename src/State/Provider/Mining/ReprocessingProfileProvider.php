<?php

declare(strict_types=1);

namespace App\State\Provider\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mining\ReprocessingProfileResource;
use App\Entity\User;
use App\Repository\ReprocessingProfileRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ReprocessingProfileResource>
 */
final readonly class ReprocessingProfileProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private ReprocessingProfileRepository $profileRepository,
        private ReprocessingProfileResourceMapper $resourceMapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ReprocessingProfileResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        return $this->resourceMapper->fromProfile($this->profileRepository->findOneBy(['user' => $user]));
    }
}
