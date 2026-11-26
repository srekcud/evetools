<?php

declare(strict_types=1);

namespace App\State\Processor\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Notification\DiscordWebhookInput;
use App\ApiResource\Notification\DiscordWebhookResource;
use App\Entity\User;
use App\Entity\UserDiscordWebhook;
use App\Repository\UserDiscordWebhookRepository;
use App\Service\Notification\DiscordWebhookUrlCipher;
use App\Service\Notification\DiscordWebhookUrlValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProcessorInterface<DiscordWebhookInput, DiscordWebhookResource>
 */
class DiscordWebhookSaveProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UserDiscordWebhookRepository $repository,
        private readonly DiscordWebhookUrlValidator $validator,
        private readonly DiscordWebhookUrlCipher $cipher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DiscordWebhookResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof DiscordWebhookInput);

        try {
            $url = $this->validator->validate($data->url);
        } catch (\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage());
        }

        $webhook = $this->repository->findByUser($user);

        if ($webhook === null) {
            $webhook = (new UserDiscordWebhook())->setUser($user);
            $this->entityManager->persist($webhook);
        }

        $webhook->setUrlEncrypted($this->cipher->encrypt($url));
        $this->entityManager->flush();

        $resource = new DiscordWebhookResource();
        $resource->configured = true;

        return $resource;
    }
}
