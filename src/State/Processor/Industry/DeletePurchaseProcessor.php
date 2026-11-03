<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\StepPurchaseResource;
use App\Repository\IndustryStepPurchaseRepository;
use App\Service\UuidOrNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<StepPurchaseResource, void>
 */
class DeletePurchaseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IndustryStepPurchaseRepository $purchaseRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $purchase = $this->purchaseRepository->find(UuidOrNotFound::of($data->id, 'Purchase not found'));

        if ($purchase === null) {
            throw new NotFoundHttpException('Purchase not found');
        }

        $this->entityManager->remove($purchase);
        $this->entityManager->flush();
    }
}
