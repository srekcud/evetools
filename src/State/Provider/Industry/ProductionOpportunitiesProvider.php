<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ProductionOpportunitiesResource;
use App\Entity\User;
use App\Service\Industry\Opportunities\OpportunityMarket;
use App\Service\Industry\Opportunities\OpportunitySort;
use App\Service\Industry\Opportunities\ProductionOpportunities;
use App\Service\Industry\Opportunities\ProductionOpportunity;
use App\Service\Industry\SellMarkets\SellMarketComparison;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProductionOpportunitiesResource>
 */
class ProductionOpportunitiesProvider implements ProviderInterface
{
    private const int MINIMUM_CAPITAL = 1;
    private const int MAXIMUM_CAPITAL = 1_000_000_000_000_000;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly ProductionOpportunities $opportunities,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProductionOpportunitiesResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $result = $this->opportunities->for($user, $this->requestedMarket(), $this->requestedSort(), $this->requestedCapital());

        $resource = new ProductionOpportunitiesResource();
        $resource->capitalCap = $result->capitalCap;
        $resource->capitalStructureName = $result->capitalStructureName;
        $resource->freeSlots = null === $result->freeSlots ? null : ['manufacturing' => $result->freeSlots->manufacturing, 'reaction' => $result->freeSlots->reaction];
        $resource->sellShareOfDailyVolume = SellMarketComparison::SELL_SHARE_OF_DAILY_VOLUME;
        $resource->salesFeesKnown = $result->salesFeesKnown;
        $resource->unknownProductCount = $result->unknownProductCount;
        $resource->rows = array_map($this->rowOf(...), $result->rows);

        return $resource;
    }

    private function requestedMarket(): OpportunityMarket
    {
        $raw = $this->requestStack->getCurrentRequest()?->query->get('market', OpportunityMarket::All->value);
        $market = \is_string($raw) ? OpportunityMarket::tryFrom($raw) : null;
        if (null === $market) {
            throw new BadRequestHttpException('market must be "all", "jita" or "capital".');
        }

        return $market;
    }

    private function requestedSort(): OpportunitySort
    {
        $raw = $this->requestStack->getCurrentRequest()?->query->get('sort', OpportunitySort::WeeklyProfit->value);
        $sort = \is_string($raw) ? OpportunitySort::tryFrom($raw) : null;
        if (null === $sort) {
            throw new BadRequestHttpException('sort must be "week" or "hour".');
        }

        return $sort;
    }

    private function requestedCapital(): ?int
    {
        $query = $this->requestStack->getCurrentRequest()?->query;
        if (null === $query || !$query->has('capital')) {
            return null;
        }

        $raw = $query->get('capital');
        $capital = \is_string($raw) ? filter_var($raw, FILTER_VALIDATE_INT) : false;
        if (false === $capital || $capital < self::MINIMUM_CAPITAL || $capital > self::MAXIMUM_CAPITAL) {
            throw new BadRequestHttpException(\sprintf('capital must be an integer between %d and %d.', self::MINIMUM_CAPITAL, self::MAXIMUM_CAPITAL));
        }

        return $capital;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowOf(ProductionOpportunity $opportunity): array
    {
        return [
            'typeId' => $opportunity->typeId,
            'typeName' => $opportunity->typeName,
            'categoryLabel' => $opportunity->categoryLabel,
            'market' => $opportunity->market,
            'sellPrice' => $opportunity->sellPrice,
            'dailyVolume' => $opportunity->dailyVolume,
            'unitCost' => $opportunity->unitCost,
            'profitPerUnit' => $opportunity->profitPerUnit,
            'marginPercent' => $opportunity->marginPercent,
            'weeklyQuantity' => $opportunity->weeklyQuantity,
            'limitedBy' => $opportunity->limitedBy?->value,
            'weeklyProfit' => $opportunity->weeklyProfit,
            'iskPerSlotHour' => $opportunity->iskPerSlotHour,
            'missingSkills' => array_map(
                static fn ($skill): array => ['skillId' => $skill->skillId, 'name' => $skill->name, 'level' => $skill->level],
                $opportunity->missingSkills,
            ),
            'groupHint' => $opportunity->groupHint,
        ];
    }
}
