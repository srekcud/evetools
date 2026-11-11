<?php

declare(strict_types=1);

namespace App\State\Processor\Market;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Market\UpdateAlertInput;
use App\ApiResource\Market\MarketAlertResource;
use App\Entity\User;
use App\Enum\AlertStatus;
use App\Repository\MarketPriceAlertRepository;
use App\Service\MarketAlertRules;
use App\Service\UuidOrNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<UpdateAlertInput, MarketAlertResource>
 */
class UpdateAlertProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MarketPriceAlertRepository $alertRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MarketAlertResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof UpdateAlertInput);

        $alert = $this->alertRepository->find(UuidOrNotFound::of((string) ($uriVariables['id'] ?? ''), 'Alert not found'));

        if ($alert === null) {
            throw new NotFoundHttpException('Alert not found');
        }

        if (!$alert->isOwnedBy($user)) {
            throw new AccessDeniedHttpException('Access denied');
        }

        if ($alert->getStatus() === AlertStatus::Expired) {
            throw new ConflictHttpException('An expired alert cannot be updated');
        }

        $direction = $data->direction === null ? null : MarketAlertRules::directionOrBadRequest($data->direction);

        if ($data->threshold !== null) {
            MarketAlertRules::assertThresholdIsPositive($data->threshold);
        }

        if ($direction !== null) {
            $alert->setDirection($direction);
        }

        if ($data->threshold !== null) {
            $alert->setThreshold($data->threshold);
        }

        if ($alert->getStatus() === AlertStatus::Triggered) {
            $alert->setStatus(AlertStatus::Active);
            $alert->setTriggeredAt(null);
            $alert->setTriggeredPrice(null);
        }

        $this->em->flush();

        $resource = new MarketAlertResource();
        $resource->id = $alert->getId()?->toRfc4122() ?? '';
        $resource->typeId = $alert->getTypeId();
        $resource->typeName = $alert->getTypeName();
        $resource->direction = $alert->getDirection()->value;
        $resource->threshold = $alert->getThreshold();
        $resource->priceSource = $alert->getPriceSource()->value;
        $resource->status = $alert->getStatus()->value;
        $resource->triggeredAt = $alert->getTriggeredAt()?->format('c');
        $resource->triggeredPrice = $alert->getTriggeredPrice();
        $resource->createdAt = $alert->getCreatedAt()->format('c');

        return $resource;
    }
}
