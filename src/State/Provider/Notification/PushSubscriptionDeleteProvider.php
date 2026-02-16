<?php

declare(strict_types=1);

namespace App\State\Provider\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Notification\PushSubscriptionResource;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PushSubscriptionResource>
 */
class PushSubscriptionDeleteProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PushSubscriptionResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        return new PushSubscriptionResource();
    }
}
