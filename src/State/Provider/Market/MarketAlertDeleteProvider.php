<?php

declare(strict_types=1);

namespace App\State\Provider\Market;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Market\MarketAlertResource;
use App\Entity\User;
use App\Repository\MarketPriceAlertRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<MarketAlertResource>
 */
class MarketAlertDeleteProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MarketPriceAlertRepository $alertRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MarketAlertResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $id = $uriVariables['id'] ?? null;
        if ($id === null) {
            throw new NotFoundHttpException('Alert not found');
        }

        $alert = $this->alertRepository->find(Uuid::fromString((string) $id));
        if ($alert === null) {
            throw new NotFoundHttpException('Alert not found');
        }

        if (!$alert->isOwnedBy($user)) {
            throw new AccessDeniedHttpException('Access denied');
        }

        $resource = new MarketAlertResource();
        $resource->id = $alert->getId()?->toRfc4122() ?? '';
        $resource->typeId = $alert->getTypeId();
        $resource->typeName = $alert->getTypeName();
        $resource->direction = $alert->getDirection();
        $resource->threshold = $alert->getThreshold();
        $resource->priceSource = $alert->getPriceSource();
        $resource->status = $alert->getStatus();
        $resource->triggeredAt = $alert->getTriggeredAt()?->format('c');
        $resource->createdAt = $alert->getCreatedAt()->format('c');

        return $resource;
    }
}
