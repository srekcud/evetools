<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\StockpileTargetResource;
use App\ApiResource\Input\Industry\CreateStockpileTargetInput;
use App\Entity\IndustryStockpileTarget;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<CreateStockpileTargetInput, StockpileTargetResource>
 */
class CreateStockpileTargetProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StockpileTargetResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof CreateStockpileTargetInput);

        $target = new IndustryStockpileTarget();
        $target->setUser($user);
        $target->setTypeId($data->typeId);
        $target->setTypeName($data->typeName);
        $target->setTargetQuantity($data->targetQuantity);
        $target->setStage($data->stage);

        $this->entityManager->persist($target);
        $this->entityManager->flush();

        $resource = new StockpileTargetResource();
        $resource->id = $target->getId()->toRfc4122();
        $resource->typeId = $target->getTypeId();
        $resource->typeName = $target->getTypeName();
        $resource->targetQuantity = $target->getTargetQuantity();
        $resource->stage = $target->getStage();
        $resource->sourceProductTypeId = $target->getSourceProductTypeId();
        $resource->createdAt = $target->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $resource->updatedAt = null;

        return $resource;
    }
}
