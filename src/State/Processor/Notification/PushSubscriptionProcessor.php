<?php

declare(strict_types=1);

namespace App\State\Processor\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Notification\PushSubscriptionInput;
use App\ApiResource\Notification\PushSubscriptionResource;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<PushSubscriptionInput, PushSubscriptionResource>
 */
class PushSubscriptionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PushSubscriptionRepository $pushSubscriptionRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PushSubscriptionResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof PushSubscriptionInput);

        // Validate endpoint URL
        if (!filter_var($data->endpoint, FILTER_VALIDATE_URL) || !str_starts_with($data->endpoint, 'https://')) {
            throw new BadRequestHttpException('Invalid push endpoint URL');
        }

        // Check if already exists (upsert)
        $existing = $this->pushSubscriptionRepository->findByUserAndEndpoint($user, $data->endpoint);

        if ($existing !== null) {
            // Update keys in case they changed
            $existing->setPublicKey($data->publicKey);
            $existing->setAuthToken($data->authToken);
        } else {
            $subscription = new PushSubscription();
            $subscription->setUser($user);
            $subscription->setEndpoint($data->endpoint);
            $subscription->setPublicKey($data->publicKey);
            $subscription->setAuthToken($data->authToken);

            $this->entityManager->persist($subscription);
        }

        $this->entityManager->flush();

        $resource = new PushSubscriptionResource();
        $resource->success = true;

        return $resource;
    }
}
