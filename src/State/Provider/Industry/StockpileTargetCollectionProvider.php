<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\StockpileTargetResource;
use App\Entity\IndustryStockpileTarget;
use App\Entity\User;
use App\Repository\IndustryStockpileTargetRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<StockpileTargetResource>
 */
class StockpileTargetCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryStockpileTargetRepository $targetRepository,
    ) {
    }

    /**
     * @return list<StockpileTargetResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $targets = $this->targetRepository->findByUser($user);

        return array_map([$this, 'mapToResource'], $targets);
    }

    private function mapToResource(IndustryStockpileTarget $target): StockpileTargetResource
    {
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
