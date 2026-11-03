<?php

declare(strict_types=1);

namespace App\State\Provider\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mining\ReprocessingProfileResource;
use App\Entity\User;
use App\Repository\ReprocessingProfileRepository;
use App\Repository\Sde\InvTypeRepository;
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
        private InvTypeRepository $invTypeRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ReprocessingProfileResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        return ReprocessingProfileResource::fromProfile(
            $this->profileRepository->findOneBy(['user' => $user]),
            $this->invTypeRepository->findOreProcessingSkills(),
        );
    }
}
