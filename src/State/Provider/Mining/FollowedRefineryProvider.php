<?php

declare(strict_types=1);

namespace App\State\Provider\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mining\FollowedRefineryResource;
use App\Repository\MoonRefineryFollowRepository;
use App\Service\MiningOperation\MiningOperationRequest;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<FollowedRefineryResource>
 */
final readonly class FollowedRefineryProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MoonRefineryFollowRepository $followRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): FollowedRefineryResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $follow = $this->followRepository->findOneBy(['user' => $user, 'structureId' => (int) $uriVariables['structureId']])
            ?? throw new NotFoundHttpException('Refinery follow not found');

        return FollowedRefineryResource::fromFollow($follow);
    }
}
