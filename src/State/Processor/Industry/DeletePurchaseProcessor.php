<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectPurchaseResource;
use App\Repository\IndustryProjectPurchaseRepository;
use App\Service\UuidOrNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<ProjectPurchaseResource, void>
 */
class DeletePurchaseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IndustryProjectPurchaseRepository $purchaseRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $purchase = $this->purchaseRepository->find(UuidOrNotFound::of($data->id, 'Purchase not found'));

        if ($purchase === null) {
            throw new NotFoundHttpException('Purchase not found');
        }

        $purchase->getBomLine()->getPurchases()->removeElement($purchase);
        $this->entityManager->remove($purchase);
        $this->entityManager->flush();
    }
}
