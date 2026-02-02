<?php

declare(strict_types=1);

namespace App\State\Processor\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Repository\PveExpenseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, void>
 */
class DeleteExpenseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PveExpenseRepository $expenseRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $expense = $this->expenseRepository->find($uriVariables['id']);

        if ($expense === null) {
            throw new NotFoundHttpException('Expense not found');
        }

        if ($expense->getUser() !== $user) {
            throw new AccessDeniedHttpException('Forbidden');
        }

        $this->entityManager->remove($expense);
        $this->entityManager->flush();
    }
}
