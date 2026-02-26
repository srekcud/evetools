<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\BpcPriceResource;
use App\Entity\IndustryBpcPrice;
use App\Entity\User;
use App\Repository\IndustryBpcPriceRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<BpcPriceResource>
 */
class BpcPriceCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryBpcPriceRepository $bpcPriceRepository,
    ) {
    }

    /**
     * @return list<BpcPriceResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $entities = $this->bpcPriceRepository->findByUser($user);

        return array_map([$this, 'mapToResource'], $entities);
    }

    private function mapToResource(IndustryBpcPrice $entity): BpcPriceResource
    {
        $resource = new BpcPriceResource();
        $resource->blueprintTypeId = $entity->getBlueprintTypeId();
        $resource->pricePerRun = $entity->getPricePerRun();
        $resource->updatedAt = $entity->getUpdatedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
