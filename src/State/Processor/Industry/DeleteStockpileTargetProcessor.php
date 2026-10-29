<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Repository\IndustryStockpileImportRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Service\UuidOrNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, void>
 */
class DeleteStockpileTargetProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryStockpileTargetRepository $targetRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly IndustryStockpileImportRepository $importRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $target = $this->targetRepository->find(UuidOrNotFound::of($uriVariables['id'], 'Stockpile target not found'));

        if ($target === null || $target->getUser() !== $user) {
            throw new NotFoundHttpException('Stockpile target not found');
        }

        $this->entityManager->remove($target);
        $this->entityManager->flush();

        $sourceProductTypeId = $target->getSourceProductTypeId();
        if ($sourceProductTypeId !== null && $this->targetRepository->count(['user' => $user, 'sourceProductTypeId' => $sourceProductTypeId]) === 0) {
            $this->importRepository->deleteAllForUserAndProduct($user, $sourceProductTypeId);
        }
    }
}
