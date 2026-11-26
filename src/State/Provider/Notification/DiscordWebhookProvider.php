<?php

declare(strict_types=1);

namespace App\State\Provider\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Notification\DiscordWebhookResource;
use App\Entity\User;
use App\Repository\UserDiscordWebhookRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<DiscordWebhookResource>
 */
class DiscordWebhookProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UserDiscordWebhookRepository $repository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DiscordWebhookResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $resource = new DiscordWebhookResource();
        $resource->configured = $this->repository->findByUser($user) !== null;

        return $resource;
    }
}
