<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\BpcPriceResource;
use App\Entity\User;
use App\Repository\IndustryBpcPriceRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<BpcPriceResource>
 */
class BpcPriceItemProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryBpcPriceRepository $bpcPriceRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BpcPriceResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $blueprintTypeId = (int) $uriVariables['blueprintTypeId'];
        $entity = $this->bpcPriceRepository->findByUserAndBlueprint($user, $blueprintTypeId);

        if ($entity === null) {
            throw new NotFoundHttpException('BPC price not found');
        }

        $resource = new BpcPriceResource();
        $resource->blueprintTypeId = $entity->getBlueprintTypeId();
        $resource->pricePerRun = $entity->getPricePerRun();
        $resource->updatedAt = $entity->getUpdatedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
