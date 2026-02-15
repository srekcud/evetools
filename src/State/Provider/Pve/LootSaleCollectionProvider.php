<?php

declare(strict_types=1);

namespace App\State\Provider\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Pve\LootSaleResource;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Repository\PveIncomeRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<LootSaleResource>
 */
class LootSaleCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PveIncomeRepository $incomeRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** @return list<LootSaleResource> */
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

        $lootSales = $this->incomeRepository->findByUserAndDateRange($user, $from, $to);

        return array_values(array_map(fn(PveIncome $i) => $this->toResource($i), $lootSales));
    }

    private function toResource(PveIncome $income): LootSaleResource
    {
        $resource = new LootSaleResource();
        $resource->id = $income->getId()?->toRfc4122() ?? '';
        $resource->type = $income->getType();
        $resource->description = $income->getDescription();
        $resource->amount = $income->getAmount();
        $resource->date = $income->getDate()->format('Y-m-d');

        return $resource;
    }
}
