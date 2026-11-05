<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\SellMarketResource;
use App\Entity\User;
use App\Service\Industry\PartialPricePresenter;
use App\Service\Industry\SellMarkets\ImmediateSale;
use App\Service\Industry\SellMarkets\SellMarketComparison;
use App\Service\Industry\SellMarkets\SellMarketRow;
use App\Service\TypeNameResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<SellMarketResource>
 */
class SellMarketProvider implements ProviderInterface
{
    public const int MAXIMUM_QUANTITY = 100_000_000;
    private const int MINIMUM_QUANTITY = 1;
    private const int MINIMUM_TYPE_ID = 1;
    private const int MAXIMUM_TYPE_ID = 2_147_483_647;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly SellMarketComparison $sellMarketComparison,
        private readonly TypeNameResolver $typeNameResolver,
        private readonly ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SellMarketResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $typeId = $this->requestedTypeId($uriVariables['typeId'] ?? null);
        $quantity = $this->requestedQuantity();

        $comparison = $this->sellMarketComparison->compare($user, $typeId, $quantity, $this->clock->now());

        $resource = new SellMarketResource();
        $resource->typeId = $comparison->typeId;
        $resource->typeName = $this->typeNameResolver->findName($typeId);
        $resource->quantity = $comparison->quantity;
        $resource->sellShareOfDailyVolume = SellMarketComparison::SELL_SHARE_OF_DAILY_VOLUME;
        $resource->markets = array_map($this->marketLine(...), $comparison->markets);
        $resource->immediateSale = $this->immediateSaleLine($comparison->immediateSale);

        return $resource;
    }

    private function requestedTypeId(mixed $rawTypeId): int
    {
        $typeId = \is_string($rawTypeId) || \is_int($rawTypeId) ? filter_var($rawTypeId, FILTER_VALIDATE_INT) : false;

        if (false === $typeId || $typeId < self::MINIMUM_TYPE_ID || $typeId > self::MAXIMUM_TYPE_ID) {
            throw new BadRequestHttpException(\sprintf('typeId must be an integer between %d and %d.', self::MINIMUM_TYPE_ID, self::MAXIMUM_TYPE_ID));
        }

        return $typeId;
    }

    private function requestedQuantity(): int
    {
        $rawQuantity = $this->requestStack->getCurrentRequest()?->query->get('quantity');
        $quantity = \is_string($rawQuantity) ? filter_var($rawQuantity, FILTER_VALIDATE_INT) : false;

        if (false === $quantity || $quantity < self::MINIMUM_QUANTITY || $quantity > self::MAXIMUM_QUANTITY) {
            throw new BadRequestHttpException(\sprintf('quantity must be an integer between %d and %d.', self::MINIMUM_QUANTITY, self::MAXIMUM_QUANTITY));
        }

        return $quantity;
    }

    /**
     * @return array{key: string, name: string, unitPrice: ?float, coveragePercent: ?float, dailyVolume: ?float, volumeKind: string, feeRate: ?float, netUnitPrice: ?float, daysToSell: ?float}
     */
    private function marketLine(SellMarketRow $row): array
    {
        return [
            'key' => $row->key,
            'name' => $row->name,
            'unitPrice' => $row->unitPrice,
            'coveragePercent' => $this->coveragePercentOf($row->coverage),
            'dailyVolume' => $row->dailyVolume,
            'volumeKind' => $row->volumeKind,
            'feeRate' => $row->feeRate,
            'netUnitPrice' => $row->netUnitPrice,
            'daysToSell' => $row->daysToSell,
        ];
    }

    /**
     * @return array{averageUnitPrice: ?float, coveragePercent: ?float, netUnitPrice: ?float}
     */
    private function immediateSaleLine(ImmediateSale $immediateSale): array
    {
        return [
            'averageUnitPrice' => $immediateSale->averageUnitPrice,
            'coveragePercent' => $this->coveragePercentOf($immediateSale->coverage),
            'netUnitPrice' => $immediateSale->netUnitPrice,
        ];
    }

    private function coveragePercentOf(?float $coverage): ?float
    {
        return null === $coverage ? null : PartialPricePresenter::coveragePercentOfFraction($coverage);
    }
}
