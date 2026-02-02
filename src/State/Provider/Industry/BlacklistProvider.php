<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\BlacklistResource;
use App\Entity\User;
use App\Service\Industry\IndustryBlacklistService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<BlacklistResource>
 */
class BlacklistProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryBlacklistService $blacklistService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BlacklistResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $resource = new BlacklistResource();
        $resource->categories = $this->blacklistService->getCategories($user);
        $resource->items = $this->blacklistService->getBlacklistedItems($user);

        return $resource;
    }
}
