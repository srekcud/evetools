<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\StockpileTargetResource;
use App\Entity\User;
use App\Repository\IndustryStockpileTargetRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<StockpileTargetResource>
 */
class StockpileTargetProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryStockpileTargetRepository $targetRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StockpileTargetResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $target = $this->targetRepository->find(Uuid::fromString($uriVariables['id']));

        if ($target === null || $target->getUser() !== $user) {
            throw new NotFoundHttpException('Stockpile target not found');
        }

        $resource = new StockpileTargetResource();
        $resource->id = $target->getId()->toRfc4122();
        $resource->typeId = $target->getTypeId();
        $resource->typeName = $target->getTypeName();
        $resource->targetQuantity = $target->getTargetQuantity();
        $resource->stage = $target->getStage();
        $resource->sourceProductTypeId = $target->getSourceProductTypeId();
        $resource->createdAt = $target->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $resource->updatedAt = $target->getUpdatedAt()?->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
