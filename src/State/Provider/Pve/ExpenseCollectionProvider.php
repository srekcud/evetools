<?php

declare(strict_types=1);

namespace App\State\Provider\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Pve\ExpenseResource;
use App\Entity\PveExpense;
use App\Entity\User;
use App\Repository\PveExpenseRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ExpenseResource>
 */
class ExpenseCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PveExpenseRepository $expenseRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @return ExpenseResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $days = (int) ($request?->query->get('days', 30) ?? 30);
        $from = new \DateTimeImmutable("-{$days} days");
        $to = new \DateTimeImmutable();

        $expenses = $this->expenseRepository->findByUserAndDateRange($user, $from, $to);

        return array_map(fn(PveExpense $e) => $this->toResource($e), $expenses);
    }

    private function toResource(PveExpense $expense): ExpenseResource
    {
        $resource = new ExpenseResource();
        $resource->id = $expense->getId()?->toRfc4122() ?? '';
        $resource->type = $expense->getType();
        $resource->description = $expense->getDescription();
        $resource->amount = $expense->getAmount();
        $resource->date = $expense->getDate()->format('Y-m-d');

        return $resource;
    }
}
