<?php

declare(strict_types=1);

namespace App\State\Provider\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Pve\LootSaleResource;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Enum\PveIncomeType;
use App\Repository\PveIncomeRepository;
use App\Service\Pve\RequestedDays;
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
        $days = RequestedDays::fromRequest($request);
        $from = new \DateTimeImmutable("-{$days} days");
        $to = new \DateTimeImmutable();

        $lootSales = $this->incomeRepository->findByUserAndDateRange($user, $from, $to);

        $withoutEscalations = array_filter(
            $lootSales,
            static fn (PveIncome $income): bool => $income->getType() !== PveIncomeType::Escalation,
        );

        return array_values(array_map(fn(PveIncome $i) => $this->toResource($i), $withoutEscalations));
    }

    private function toResource(PveIncome $income): LootSaleResource
    {
        $resource = new LootSaleResource();
        $resource->id = $income->getId()?->toRfc4122() ?? '';
        $resource->type = $income->getType()->value;
        $resource->description = $income->getDescription();
        $resource->amount = $income->getAmount();
        $resource->date = $income->getDate()->format('Y-m-d');

        return $resource;
    }
}
