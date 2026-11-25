<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryRevenueResource;
use App\Entity\User;
use App\Service\Industry\SellOrders\ProjectSellOrder;
use App\Service\Industry\SellOrders\ProjectSellOrdersReader;
use App\Service\Planetary\PlanetaryProductTypes;
use App\Service\Planetary\PlanetaryRevenueCalculator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlanetaryRevenueResource>
 */
final readonly class PlanetaryRevenueProvider implements ProviderInterface
{
    private const DEFAULT_DAYS = 30;
    private const MIN_DAYS = 1;
    private const MAX_DAYS = 365;

    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private PlanetaryRevenueCalculator $calculator,
        private PlanetaryProductTypes $productTypes,
        private ProjectSellOrdersReader $sellOrdersReader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetaryRevenueResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $days = $this->requestedDays();
        $report = $this->calculator->calculate($user, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $days);

        $resource = new PlanetaryRevenueResource();
        $resource->periodEnd = $report['periodEnd'];
        $resource->days = $report['days'];
        $resource->tiers = $report['tiers'];
        $resource->realisedIsk = $report['realisedIsk'];
        $resource->realisedChange = $report['realisedChange'];
        $resource->realisedDaily = $report['realisedDaily'];
        $resource->realisedContributors = $report['realisedContributors'];
        $resource->estimate = $report['estimate'];
        $resource->toFix = $report['toFix'];
        $resource->unsyncedWallets = $report['unsyncedWallets'];
        $resource->sellOrders = $this->sellOrdersOf($user);

        return $resource;
    }

    /**
     * @return array{orders: list<array{orderId: int, characterName: string, typeId: int, typeName: string, volumeRemain: int, locationId: int, marketName: string|null, price: float, bestPrice: float|null, rank: int|null, state: string, unknownReason: string|null, undercutSince: string|null, suggestedPrice: float|null}>, charactersToReconnect: list<string>, syncedAt: string|null}
     */
    private function sellOrdersOf(User $user): array
    {
        $products = $this->productTypes->of($user);
        if ($products === []) {
            return ['orders' => [], 'charactersToReconnect' => [], 'syncedAt' => null];
        }

        $sellOrders = $this->sellOrdersReader->forProducts($user, $products);

        return [
            'orders' => array_map(static fn (ProjectSellOrder $order): array => $order->toArray(), $sellOrders->orders),
            'charactersToReconnect' => $sellOrders->charactersToReconnect,
            'syncedAt' => $sellOrders->syncedAt?->format(\DATE_ATOM),
        ];
    }

    private function requestedDays(): int
    {
        $requested = $this->requestStack->getCurrentRequest()?->query->get('days');
        if ($requested === null) {
            return self::DEFAULT_DAYS;
        }

        $days = filter_var($requested, FILTER_VALIDATE_INT);
        if ($days === false || $days < self::MIN_DAYS || $days > self::MAX_DAYS) {
            throw new BadRequestHttpException(sprintf('days must be an integer between %d and %d', self::MIN_DAYS, self::MAX_DAYS));
        }

        return $days;
    }
}
