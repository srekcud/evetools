<?php

declare(strict_types=1);

namespace App\State\Processor\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Notification\DiscordWebhookResource;
use App\Entity\User;
use App\Repository\UserDiscordWebhookRepository;
use App\Service\Notification\DiscordWebhookSender;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProcessorInterface<mixed, DiscordWebhookResource>
 */
class DiscordWebhookTestProcessor implements ProcessorInterface
{
    private const string TEST_TITLE = 'Test';
    private const string TEST_MESSAGE = 'Your Discord webhook is configured: planetary alerts will be posted here.';
    private const int BAD_GATEWAY_STATUS = 502;

    public function __construct(
        private readonly Security $security,
        private readonly UserDiscordWebhookRepository $repository,
        private readonly DiscordWebhookSender $sender,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DiscordWebhookResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        if ($this->repository->findByUser($user) === null) {
            throw new UnprocessableEntityHttpException('No Discord webhook is configured');
        }

        try {
            $this->sender->send($user, self::TEST_TITLE, self::TEST_MESSAGE);
        } catch (\RuntimeException $exception) {
            throw new HttpException(self::BAD_GATEWAY_STATUS, $exception->getMessage());
        }

        $resource = new DiscordWebhookResource();
        $resource->configured = true;

        return $resource;
    }
}
