<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\BpcPriceResource;
use App\ApiResource\Input\Industry\UpsertBpcPriceInput;
use App\Entity\IndustryBpcPrice;
use App\Entity\User;
use App\Repository\IndustryBpcPriceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<UpsertBpcPriceInput, BpcPriceResource>
 */
class UpsertBpcPriceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryBpcPriceRepository $bpcPriceRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BpcPriceResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof UpsertBpcPriceInput);

        $entity = $this->bpcPriceRepository->findByUserAndBlueprint($user, $data->blueprintTypeId);

        if ($entity === null) {
            $entity = new IndustryBpcPrice();
            $entity->setUser($user);
            $entity->setBlueprintTypeId($data->blueprintTypeId);
            $this->entityManager->persist($entity);
        }

        $entity->setPricePerRun($data->pricePerRun);
        $this->entityManager->flush();

        $resource = new BpcPriceResource();
        $resource->blueprintTypeId = $entity->getBlueprintTypeId();
        $resource->pricePerRun = $entity->getPricePerRun();
        $resource->updatedAt = $entity->getUpdatedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
